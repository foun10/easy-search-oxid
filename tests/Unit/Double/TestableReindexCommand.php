<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Command\ReindexCommand;

/**
 * ReindexCommand with the installation's shops supplied by the test.
 *
 * The two seams are the only place the command asks the shop anything: which
 * shops exist when no --shop-id narrows the run, and which shop the console
 * booted as, whose engine setting then applies to the whole run.
 */
class TestableReindexCommand extends ReindexCommand
{
    /** @var int[] */
    public array $allShopIds = [1];

    public int $currentShopId = 1;

    protected function getAllShopIds(): array
    {
        return $this->allShopIds;
    }

    protected function getCurrentShopId(): int
    {
        return $this->currentShopId;
    }
}
