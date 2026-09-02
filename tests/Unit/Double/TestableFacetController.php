<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Controller\FacetController;
use RuntimeException;
use Throwable;

/**
 * FacetController with the request, the container and the response supplied by
 * the test.
 *
 * The response seams are the interesting ones. This endpoint answers JSON and
 * ends the request, so in production the last thing it does is exit - which a
 * test cannot follow. Splitting that into setHeader() and exitWith() leaves the
 * headers and the body as two things a test can read, and the headers are worth
 * reading: one of them is a deliberate instruction to reverse proxies not to
 * cache an answer that depends on the customer's group.
 */
class TestableFacetController extends FacetController
{
    public FakeRequest $request;

    public int $currentShopId = 1;

    public int $currentLanguageId = 0;

    /** @var array<string, object> Container entries, keyed by service id */
    public array $services = [];

    /** @var string[] Headers sent, in order */
    public array $headers = [];

    /** The JSON body the endpoint ended the request with, if it got that far */
    public ?string $body = null;

    /** @var string[] Messages that went to the log */
    public array $loggedErrors = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(array $parameters = [])
    {
        $this->request = new FakeRequest($parameters);
    }

    /**
     * The payload as the browser would parse it back.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->body === null ? [] : (array) json_decode($this->body, true);
    }

    protected function getRequest()
    {
        return $this->request;
    }

    protected function setHeader(string $header): void
    {
        $this->headers[] = $header;
    }

    protected function exitWith(string $body): void
    {
        $this->body = $body;
    }

    protected function getCurrentShopId(): int
    {
        return $this->currentShopId;
    }

    protected function getCurrentLanguageId(): int
    {
        return $this->currentLanguageId;
    }

    protected function logError(string $message, Throwable $exception): void
    {
        $this->loggedErrors[] = $message;
    }

    protected function getService(string $id): object
    {
        return $this->services[$id]
            ?? throw new RuntimeException('no service registered for ' . $id);
    }
}
