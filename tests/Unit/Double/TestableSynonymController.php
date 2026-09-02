<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Controller\Admin\SynonymController;
use RuntimeException;

/**
 * SynonymController with the request, the session, the container and the
 * translations supplied by the test.
 *
 * The class extends OXID's AdminController, and that is the only reason this
 * suite needs a stand-in for it at all - the controller uses nothing from its
 * parent (see tests/Stub/AdminController.php). Everything it does use goes
 * through a seam below, so what is left to test is the screen's own reasoning:
 * what a submitted row means, how many blank rows to offer, and when a saved
 * count may be shown.
 */
class TestableSynonymController extends SynonymController
{
    public FakeRequest $request;

    public int $currentShopId = 1;

    /** @var array<string, mixed> The session, as far as this screen is concerned */
    public array $session = [];

    /** @var string[] Variables that were cleared, in order */
    public array $deletedSessionVariables = [];

    /** @var array<string, object> Container entries, keyed by service id */
    public array $services = [];

    /** @var string[] Language keys that were asked for */
    public array $translated = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(array $parameters = [])
    {
        $this->request = new FakeRequest($parameters);
    }

    protected function getRequest()
    {
        return $this->request;
    }

    protected function getCurrentShopId(): int
    {
        return $this->currentShopId;
    }

    /**
     * The key back rather than a translation: what the screen has to get right
     * is which key it asks for, not what German says.
     */
    protected function translate(string $key): string
    {
        $this->translated[] = $key;

        return $key;
    }

    protected function setSessionVariable(string $name, mixed $value): void
    {
        $this->session[$name] = $value;
    }

    protected function getSessionVariable(string $name): mixed
    {
        return $this->session[$name] ?? null;
    }

    protected function deleteSessionVariable(string $name): void
    {
        $this->deletedSessionVariables[] = $name;
        unset($this->session[$name]);
    }

    protected function getService(string $id): object
    {
        return $this->services[$id]
            ?? throw new RuntimeException('no service registered for ' . $id);
    }

    /**
     * @return array<int, array{type: string, term: string, synonyms: string, active: bool}>
     */
    public function readSubmittedRulesPublic(): array
    {
        return $this->readSubmittedRules();
    }
}
