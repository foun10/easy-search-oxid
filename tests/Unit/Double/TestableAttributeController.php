<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Controller\Admin\AttributeController;
use RuntimeException;

/**
 * AttributeController with the request, the shop and the translations supplied
 * by the test.
 *
 * getRequest() and getService() come from the ReindexPhases trait the screen
 * already uses for its rebuild button, so they are overridden once here and
 * serve both halves of it.
 */
class TestableAttributeController extends AttributeController
{
    public FakeRequest $request;

    public int $currentShopId = 1;

    /** The language the backend is being displayed in */
    public int $templateLanguageId = 0;

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

    protected function getTemplateLanguageId(): int
    {
        return $this->templateLanguageId;
    }

    protected function translate(string $key): string
    {
        $this->translated[] = $key;

        return $key;
    }

    protected function getService(string $id): object
    {
        return $this->services[$id]
            ?? throw new RuntimeException('no service registered for ' . $id);
    }

    /**
     * @return string[]
     */
    public function toIdListPublic(mixed $value): array
    {
        return $this->toIdList($value);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function readTitlesPublic(): array
    {
        return $this->readTitles();
    }
}
