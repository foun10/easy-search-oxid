<?php
declare(strict_types=1);

namespace foun10\EasySearch\Meili;

/**
 * Minimal HTTP client for the Meilisearch API.
 *
 * Hand written on top of curl rather than pulling in meilisearch/meilisearch-php.
 * The official client brings its own PSR-18 stack, and this module needs eight
 * endpoints - documents, settings, search, stats, tasks, indexes, swap and
 * health. A dependency that has to be composer-required into the shop and kept
 * in step with the shop's own Guzzle version is a poor trade for that.
 *
 * Every call is synchronous. Writes to Meilisearch are asynchronous by design:
 * they return a task ID and the caller decides whether to wait, which is what
 * makes bulk indexing fast - see MeiliIndexWriter.
 */
class MeiliClient
{
    /**
     * Refusing quickly matters more than reaching a slow instance: the search
     * page falls back to the shop's own search when the engine is unavailable,
     * and a customer should not wait out a TCP timeout for that.
     */
    protected const CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * Generous, because the same client also carries the document pushes of a
     * rebuild.
     */
    protected const TIMEOUT_SECONDS = 120;

    /**
     * How often a wait loop asks whether a task has finished. Meilisearch has
     * no blocking endpoint for this, so it is polling either way.
     */
    protected const POLL_INTERVAL_MICROSECONDS = 50000;

    public function __construct(
        protected MeiliConfiguration $configuration
    ) {
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, null, $query);
    }

    /**
     * @param array<mixed>|null     $payload
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    public function post(string $path, ?array $payload = null, array $query = []): array
    {
        return $this->request('POST', $path, $payload, $query);
    }

    /**
     * @param array<mixed>|null     $payload
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    public function put(string $path, ?array $payload = null, array $query = []): array
    {
        return $this->request('PUT', $path, $payload, $query);
    }

    /**
     * @param array<mixed>|null $payload
     *
     * @return array<mixed>
     */
    public function patch(string $path, ?array $payload = null): array
    {
        return $this->request('PATCH', $path, $payload);
    }

    /**
     * @return array<mixed>
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * @param array<mixed>|null     $payload
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        $url = $this->configuration->getHost() . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $handle = curl_init($url);
        $headers = ['Content-Type: application/json'];
        $key = $this->configuration->getApiKey();

        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);

        if ($payload !== null) {
            curl_setopt(
                $handle,
                CURLOPT_POSTFIELDS,
                // Slashes appear in category paths, and leaving unicode
                // unescaped halves the payload on a German catalogue.
                (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new MeiliException(
                sprintf('Meilisearch unreachable at %s: %s', $url, $error),
                0,
                'connection_failed'
            );
        }

        return $this->decode((string) $body, $status, $method . ' ' . $path);
    }

    /**
     * Blocks until a write task has been processed, and fails loudly if it did
     * not succeed.
     *
     * Meilisearch acknowledges a write immediately and applies it in the
     * background, so "the request came back 202" says nothing about whether the
     * documents are searchable - or whether they were rejected. Anything that
     * has to observe its own writes goes through here.
     *
     * @return array<mixed> The finished task
     */
    public function waitForTask(int $taskUid, float $timeoutSeconds = 900.0): array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $task = $this->get('/tasks/' . $taskUid);
            $status = (string) ($task['status'] ?? '');

            if ($status === 'succeeded') {
                return $task;
            }

            if ($status === 'failed' || $status === 'canceled') {
                throw new MeiliException(
                    sprintf(
                        'Meilisearch task %d %s: %s',
                        $taskUid,
                        $status,
                        (string) ($task['error']['message'] ?? 'no message')
                    ),
                    0,
                    (string) ($task['error']['code'] ?? '')
                );
            }

            if (microtime(true) > $deadline) {
                throw new MeiliException(sprintf(
                    'Meilisearch task %d still %s after %ds',
                    $taskUid,
                    $status,
                    (int) $timeoutSeconds
                ));
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }

    /**
     * Is the instance up? Answered without throwing, so it can be used to
     * decide whether to even try.
     */
    public function isReachable(): bool
    {
        try {
            $health = $this->get('/health');
        } catch (MeiliException $exception) {
            return false;
        }

        return ($health['status'] ?? '') === 'available';
    }

    /**
     * @return array<mixed>
     */
    protected function decode(string $body, int $status, string $context): array
    {
        // 204 on a delete, and an empty body on anything that has nothing to
        // say - json_decode('') is an error, not an empty array.
        $decoded = $body === '' ? [] : json_decode($body, true);

        if ($status >= 400) {
            throw new MeiliException(
                sprintf(
                    'Meilisearch %s failed (%d): %s',
                    $context,
                    $status,
                    is_array($decoded) ? (string) ($decoded['message'] ?? $body) : $body
                ),
                $status,
                is_array($decoded) ? (string) ($decoded['code'] ?? '') : ''
            );
        }

        if (!is_array($decoded)) {
            throw new MeiliException(
                sprintf('Meilisearch %s returned no usable JSON: %s', $context, mb_substr($body, 0, 200)),
                $status
            );
        }

        return $decoded;
    }
}
