<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Engine;

use foun10\EasySearch\Engine\Query\SuggestQuery;
use foun10\EasySearch\Engine\Result\SuggestResult;
use PHPUnit\Framework\TestCase;

/**
 * The dropdown that opens while a customer is still typing.
 *
 * Both halves are plain value objects, but the emptiness rule on the result is
 * load-bearing: it decides whether the dropdown opens at all. Opening an empty
 * panel over the page on every keystroke is worse than not opening one.
 */
class SuggestTest extends TestCase
{
    public function testTheQueryCarriesItsScopeAndLimits(): void
    {
        $query = new SuggestQuery('bh', 2, 1, 10, 8, 4, 2);

        $this->assertSame('bh', $query->getTerm());
        $this->assertSame(2, $query->getShopId());
        $this->assertSame(1, $query->getLangId());
        $this->assertSame(10, $query->getTermLimit());
        $this->assertSame(8, $query->getProductLimit());
        $this->assertSame(4, $query->getCategoryLimit());
        $this->assertSame(2, $query->getBrandLimit());
    }

    /**
     * The defaults are what the dropdown shows when nothing was configured:
     * more terms and products than categories and brands, because that is the
     * order of usefulness in a suggest panel.
     */
    public function testTheDefaultLimitsFavourTermsAndProducts(): void
    {
        $query = new SuggestQuery('bh', 1, 0);

        $this->assertSame(6, $query->getTermLimit());
        $this->assertSame(6, $query->getProductLimit());
        $this->assertSame(3, $query->getCategoryLimit());
        $this->assertSame(3, $query->getBrandLimit());
    }

    public function testTheResultCarriesAllFourSections(): void
    {
        $result = new SuggestResult(['bh', 'bhs'], ['p1'], ['c1'], ['b1'], 42);

        $this->assertSame(['bh', 'bhs'], $result->getTerms());
        $this->assertSame(['p1'], $result->getProductIds());
        $this->assertSame(['c1'], $result->getCategoryIds());
        $this->assertSame(['b1'], $result->getBrandIds());
        $this->assertSame(42, $result->getTotalCount());
    }

    public function testAResultWithNothingInAnySectionIsEmpty(): void
    {
        $this->assertTrue((new SuggestResult())->isEmpty());
        $this->assertTrue(SuggestResult::empty()->isEmpty());
    }

    /**
     * Anything in any one section is enough to open the dropdown - a customer
     * typing a brand name gets a panel even when no term completion matched.
     *
     * @dataProvider populatedSectionProvider
     */
    public function testAnythingInAnySectionMakesTheResultNonEmpty(SuggestResult $result): void
    {
        $this->assertFalse($result->isEmpty());
    }

    public function populatedSectionProvider(): array
    {
        return [
            'terms only'      => [new SuggestResult(['bh'])],
            'products only'   => [new SuggestResult([], ['p1'])],
            'categories only' => [new SuggestResult([], [], ['c1'])],
            'brands only'     => [new SuggestResult([], [], [], ['b1'])],
        ];
    }

    /**
     * The total drives the "show all N results" link, and it counts what the
     * full search would return - not what fits in the dropdown. So a result
     * with a total but no rows is still empty as far as the panel is concerned.
     */
    public function testATotalAloneDoesNotOpenTheDropdown(): void
    {
        $result = new SuggestResult([], [], [], [], 99);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(99, $result->getTotalCount());
    }

    public function testAnEmptyResultCountsNothing(): void
    {
        $this->assertSame(0, SuggestResult::empty()->getTotalCount());
        $this->assertSame([], SuggestResult::empty()->getTerms());
    }
}
