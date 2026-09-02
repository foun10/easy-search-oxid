<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Index\VisibilityResolver;

/**
 * VisibilityResolver with the one shop setting it reads supplied directly.
 *
 * blUseStock arrives through a single protected method, so replacing it is
 * enough to test the whole rule without a shop - and it lets a test state
 * "stock management off" as a fact rather than arranging a configuration.
 */
class TestableVisibilityResolver extends VisibilityResolver
{
    public int $stockEnabledReads = 0;

    public function __construct(protected bool $stockEnabledForTest = true)
    {
    }

    protected function isStockEnabled(): bool
    {
        $this->stockEnabledReads++;

        return $this->stockEnabledForTest;
    }
}
