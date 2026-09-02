<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Core\ShopLanguages;

/**
 * ShopLanguages with the three shop touch points supplied by the test.
 *
 * All three are protected: which shop is in context, what the current shop's
 * language array says, and what another shop's configuration says. Replacing
 * them leaves the parts worth proving - the context-or-foreign decision, the
 * active filter, and the fallback when nothing is switched on - as ordinary
 * logic.
 */
class TestableShopLanguages extends ShopLanguages
{
    /** @var array<int, array{id: int, abbr: string, name: string}> */
    public array $contextLanguages = [];

    /** @var array<int, array<int, array{id: int, abbr: string, name: string}>> Keyed by shop id */
    public array $foreignLanguages = [];

    public int $contextCalls = 0;

    /** @var int[] */
    public array $foreignCalls = [];

    public function __construct(public int $currentShopId = 1)
    {
    }

    protected function getCurrentShopId(): int
    {
        return $this->currentShopId;
    }

    protected function getFromContext(): array
    {
        $this->contextCalls++;

        return $this->contextLanguages;
    }

    protected function getFromShopConfiguration(int $shopId): array
    {
        $this->foreignCalls[] = $shopId;

        return $this->foreignLanguages[$shopId] ?? [];
    }
}
