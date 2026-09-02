<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Controller\Admin\ReindexPhases;
use RuntimeException;
use Throwable;

/**
 * A host for the ReindexPhases trait, standing in for the two admin
 * controllers that use it.
 *
 * The trait is the whole of the browser-driven rebuild, and none of what it
 * decides needs a controller: which phase a tick runs, what the cursor and the
 * batch size become, and what JSON the browser gets back. What it does need
 * from its host is four things - the shop being edited, the request, the
 * container and a way out - and all four are supplied here.
 *
 * `getEditShopId()` is the one that really belongs to OXID's AdminController.
 * Everything else the trait touches goes through a seam of its own, which is
 * why this host needs no OXID class to exist.
 */
class TestableReindexPhases
{
    use ReindexPhases;

    /** The shop the admin is editing, which is the only scope a tick rebuilds */
    public int $editShopId = 1;

    public FakeRequest $request;

    /** @var array<string, object> Container entries, keyed by service id */
    public array $services = [];

    /** @var array<int, array<string, mixed>> Every payload handed to the browser, in order */
    public array $sent = [];

    /** @var string[] Messages that went to the log */
    public array $loggedErrors = [];

    /** @var Throwable[] The exceptions behind them */
    public array $loggedExceptions = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(array $parameters = [])
    {
        $this->request = new FakeRequest($parameters);
    }

    /**
     * The payload of the last tick.
     *
     * @return array<string, mixed>
     */
    public function lastPayload(): array
    {
        return $this->sent === [] ? [] : $this->sent[count($this->sent) - 1];
    }

    public function getEditShopId(): int
    {
        return $this->editShopId;
    }

    protected function getRequest()
    {
        return $this->request;
    }

    protected function sendJson(array $payload): void
    {
        $this->sent[] = $payload;
    }

    protected function logError(string $message, Throwable $exception): void
    {
        $this->loggedErrors[] = $message;
        $this->loggedExceptions[] = $exception;
    }

    /**
     * A service the test did not register throws, the way an unconfigured
     * container does - which is also how a tick is made to fail on demand.
     */
    protected function getService(string $id): object
    {
        return $this->services[$id]
            ?? throw new RuntimeException('no service registered for ' . $id);
    }
}
