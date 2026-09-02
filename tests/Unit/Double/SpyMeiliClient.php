<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Meili\MeiliClient;
use Throwable;

/**
 * MeiliClient with the HTTP calls recorded instead of sent.
 *
 * Answers are scripted per "METHOD /path": an array is returned as the
 * response, a Throwable is thrown (that is how a 404 or a refused index
 * creation is set up), and a callable is handed the recorded call, which is
 * what paging through documents needs - the answer depends on the offset in
 * the query.
 *
 * Anything not scripted gets the shape Meilisearch actually returns: a task
 * handle for writes, an empty body for reads. Task UIDs count up, so a test
 * can assert that the run waited for the *last* one.
 */
class SpyMeiliClient extends MeiliClient
{
    /** @var array<int, array{method: string, path: string, payload: ?array<mixed>, query: array<string, mixed>}> */
    public array $calls = [];

    /** @var int[] Task UIDs the writer waited for */
    public array $waitedFor = [];

    /** @var array<string, mixed> Keyed "METHOD /path" */
    public array $answers = [];

    public int $nextTaskUid = 1;

    public function __construct()
    {
    }

    public function get(string $path, array $query = []): array
    {
        return $this->record('GET', $path, null, $query);
    }

    public function post(string $path, ?array $payload = null, array $query = []): array
    {
        return $this->record('POST', $path, $payload, $query);
    }

    public function put(string $path, ?array $payload = null, array $query = []): array
    {
        return $this->record('PUT', $path, $payload, $query);
    }

    public function patch(string $path, ?array $payload = null): array
    {
        return $this->record('PATCH', $path, $payload, []);
    }

    public function delete(string $path): array
    {
        return $this->record('DELETE', $path, null, []);
    }

    /**
     * Waiting is recorded in the same list as the requests, because when a run
     * waits is as much a part of its behaviour as what it sends - a swap that
     * happens before the indexer has caught up publishes a half built index.
     */
    public function waitForTask(int $taskUid, float $timeoutSeconds = 900.0): array
    {
        $this->waitedFor[] = $taskUid;
        $this->calls[] = ['method' => 'WAIT', 'path' => (string) $taskUid, 'payload' => null, 'query' => []];

        return ['status' => 'succeeded'];
    }

    /**
     * Every call as "METHOD /path", which is what most assertions are about.
     *
     * @return string[]
     */
    public function trace(): array
    {
        return array_map(
            static fn (array $call): string => $call['method'] . ' ' . $call['path'],
            $this->calls
        );
    }

    /**
     * @return array<int, array{method: string, path: string, payload: ?array<mixed>, query: array<string, mixed>}>
     */
    public function callsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method && $call['path'] === $path
        ));
    }

    /**
     * @return array{method: string, path: string, payload: ?array<mixed>, query: array<string, mixed>}|null
     */
    public function firstCallTo(string $method, string $path): ?array
    {
        return $this->callsTo($method, $path)[0] ?? null;
    }

    /**
     * @param array<mixed>|null        $payload
     * @param array<string, mixed>     $query
     *
     * @return array<mixed>
     */
    protected function record(string $method, string $path, ?array $payload, array $query): array
    {
        $call = ['method' => $method, 'path' => $path, 'payload' => $payload, 'query' => $query];
        $this->calls[] = $call;

        $answer = $this->answers[$method . ' ' . $path] ?? null;

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        if (is_callable($answer)) {
            return (array) $answer($call);
        }

        if (is_array($answer)) {
            return $answer;
        }

        return $method === 'GET' ? [] : ['taskUid' => $this->nextTaskUid++];
    }
}
