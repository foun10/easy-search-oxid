<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Engine\Query;

use foun10\EasySearch\Engine\Query\FacetFilter;
use foun10\EasySearch\Engine\Query\SearchQuery;
use PHPUnit\Framework\TestCase;

/**
 * What both engines are asked for.
 *
 * The three copy methods are the interesting part. Each exists for one concrete
 * situation and quietly changes one field while carrying everything else
 * across - and a field silently dropped in a copy means the second pass of a
 * search runs against a different query than the first.
 */
class SearchQueryTest extends TestCase
{
    private function query(FacetFilter ...$filters): SearchQuery
    {
        return new SearchQuery(
            'bh',
            2,
            1,
            $filters,
            SearchQuery::SORT_PRICE_ASC,
            24,
            48,
            'cat-1',
            'man-1',
            10.0,
            99.5,
            true
        );
    }

    public function testItKeepsEverythingItWasGiven(): void
    {
        $query = $this->query(new FacetFilter('color', ['v1']));

        $this->assertSame('bh', $query->getTerm());
        $this->assertTrue($query->hasTerm());
        $this->assertSame(2, $query->getShopId());
        $this->assertSame(1, $query->getLangId());
        $this->assertSame(SearchQuery::SORT_PRICE_ASC, $query->getSort());
        $this->assertSame(24, $query->getOffset());
        $this->assertSame(48, $query->getLimit());
        $this->assertSame('cat-1', $query->getCategoryId());
        $this->assertSame('man-1', $query->getManufacturerId());
        $this->assertSame(10.0, $query->getPriceFrom());
        $this->assertSame(99.5, $query->getPriceTo());
        $this->assertTrue($query->isCorrectionAllowed());
        $this->assertCount(1, $query->getFilters());
    }

    /**
     * A category or manufacturer listing is a query without a term, so the
     * distinction has to be explicit rather than inferred from emptiness by
     * every caller.
     */
    public function testAnEmptyTermIsRecognisedAsNoTerm(): void
    {
        $this->assertFalse((new SearchQuery('', 1, 0))->hasTerm());
        $this->assertFalse((new SearchQuery('   ', 1, 0))->hasTerm());
        $this->assertTrue((new SearchQuery('bh', 1, 0))->hasTerm());
    }

    /**
     * The copy for the second pass. Correction is switched off on it, or a
     * corrected term could be corrected again and loop.
     */
    public function testTheCorrectedCopyCarriesEverythingAndStopsFurtherCorrection(): void
    {
        $original = $this->query(new FacetFilter('color', ['v1']));
        $copy = $original->withCorrectedTerm('bhs');

        $this->assertSame('bhs', $copy->getTerm());
        $this->assertFalse($copy->isCorrectionAllowed(), 'no correction loop');

        $this->assertSame($original->getShopId(), $copy->getShopId());
        $this->assertSame($original->getLangId(), $copy->getLangId());
        $this->assertSame($original->getSort(), $copy->getSort());
        $this->assertSame($original->getOffset(), $copy->getOffset());
        $this->assertSame($original->getLimit(), $copy->getLimit());
        $this->assertSame($original->getCategoryId(), $copy->getCategoryId());
        $this->assertSame($original->getManufacturerId(), $copy->getManufacturerId());
        $this->assertSame($original->getPriceFrom(), $copy->getPriceFrom());
        $this->assertSame($original->getPriceTo(), $copy->getPriceTo());
        $this->assertEquals($original->getFilters(), $copy->getFilters());
    }

    public function testTheOriginalIsNotChangedByACopy(): void
    {
        $original = $this->query();
        $original->withCorrectedTerm('bhs');

        $this->assertSame('bh', $original->getTerm());
        $this->assertTrue($original->isCorrectionAllowed());
    }

    public function testTheLimitCopyChangesOnlyTheLimit(): void
    {
        $original = $this->query();
        $copy = $original->withLimit(5);

        $this->assertSame(5, $copy->getLimit());
        $this->assertSame($original->getTerm(), $copy->getTerm());
        $this->assertSame($original->getOffset(), $copy->getOffset());
        $this->assertSame($original->isCorrectionAllowed(), $copy->isCorrectionAllowed());
    }

    /**
     * The facet endpoint wants counts but no products. Zero is not passed on,
     * because Meilisearch refuses a page size of 0 - so the caller asks for one
     * and ignores it.
     */
    public function testALimitOfZeroBecomesOne(): void
    {
        $this->assertSame(1, $this->query()->withLimit(0)->getLimit());
        $this->assertSame(1, $this->query()->withLimit(-10)->getLimit());
    }

    /**
     * A facet's own counts are calculated without its own selection applied,
     * otherwise every other colour shows zero once "red" is clicked.
     */
    public function testDroppingAFilterLeavesTheOthersInPlace(): void
    {
        $query = $this->query(
            new FacetFilter('color', ['v1']),
            new FacetFilter('size', ['s38']),
            new FacetFilter('material', ['m1'])
        );

        $copy = $query->withoutFilter('size');

        $this->assertSame(
            ['color', 'material'],
            array_map(static fn (FacetFilter $f): string => $f->getAttributeId(), $copy->getFilters())
        );
        $this->assertCount(3, $query->getFilters(), 'the original keeps all three');
    }

    /**
     * Re-indexed, because the filter list is iterated and handed on - a gapped
     * array would survive here and surprise something downstream.
     */
    public function testTheRemainingFiltersAreAList(): void
    {
        $query = $this->query(
            new FacetFilter('color', ['v1']),
            new FacetFilter('size', ['s38'])
        );

        $this->assertSame([0], array_keys($query->withoutFilter('color')->getFilters()));
    }

    public function testDroppingAFilterThatIsNotThereChangesNothing(): void
    {
        $query = $this->query(new FacetFilter('color', ['v1']));

        $this->assertCount(1, $query->withoutFilter('size')->getFilters());
    }

    public function testTheSortOptionsAreStable(): void
    {
        $this->assertSame('relevance', SearchQuery::SORT_RELEVANCE);
        $this->assertContains(SearchQuery::SORT_RELEVANCE, SearchQuery::SORT_OPTIONS);
        $this->assertContains(SearchQuery::SORT_PRICE_ASC, SearchQuery::SORT_OPTIONS);
        $this->assertSame(SearchQuery::SORT_OPTIONS, array_unique(SearchQuery::SORT_OPTIONS));
    }

    public function testTheDefaultsAreTheOnesAPlainSearchWants(): void
    {
        $query = new SearchQuery('bh', 1, 0);

        $this->assertSame([], $query->getFilters());
        $this->assertSame(SearchQuery::SORT_RELEVANCE, $query->getSort());
        $this->assertSame(0, $query->getOffset());
        $this->assertNull($query->getCategoryId());
        $this->assertNull($query->getManufacturerId());
        $this->assertNull($query->getPriceFrom());
        $this->assertNull($query->getPriceTo());
        $this->assertTrue($query->isCorrectionAllowed());
    }
}
