<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Engine\Result;

use foun10\EasySearch\Engine\Result\Correction;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Engine\Result\FacetValue;
use foun10\EasySearch\Engine\Result\SearchResult;
use PHPUnit\Framework\TestCase;

/**
 * What an engine hands back: IDs rather than products, so the controller can
 * load them through OXID and keep prices and pictures live.
 *
 * Both connectors build this, and the controllers read it without knowing which
 * one did - so it is the contract that keeps a MySQL result and a Meilisearch
 * result interchangeable.
 */
class SearchResultTest extends TestCase
{
    private function facet(string $attributeId, bool $selected = false): Facet
    {
        return new Facet(
            $attributeId,
            'Titel',
            [new FacetValue('v1', 'Rot', 2, $selected)],
            Facet::TYPE_LIST,
            0
        );
    }

    public function testItCarriesIdsAndTheirTotal(): void
    {
        $result = new SearchResult(['a', 'b'], 17);

        $this->assertSame(['a', 'b'], $result->getProductIds());
        $this->assertSame(17, $result->getTotalCount());
    }

    /**
     * The total is the whole result set, not the current page - so a page of
     * ten out of a hundred is not empty, and emptiness is decided by the total
     * rather than by how many IDs this page happens to hold.
     */
    public function testEmptinessFollowsTheTotalNotThePage(): void
    {
        $this->assertTrue((new SearchResult([], 0))->isEmpty());
        $this->assertFalse((new SearchResult([], 100))->isEmpty(), 'a later page of a large result set');
        $this->assertFalse((new SearchResult(['a'], 1))->isEmpty());
    }

    public function testAnEmptyResultCarriesNothing(): void
    {
        $result = SearchResult::empty();

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $result->getProductIds());
        $this->assertSame([], $result->getFacets());
        $this->assertNull($result->getCorrection());
        $this->assertFalse($result->hasActiveFilters());
    }

    /**
     * "Nothing found" and "nothing found, did you mean X" are different pages,
     * so the empty result has to be able to carry a correction.
     */
    public function testAnEmptyResultCanStillOfferACorrection(): void
    {
        $correction = Correction::applied('nachtemd', 'nachthemd', 1, 42);
        $result = SearchResult::empty($correction);

        $this->assertTrue($result->isEmpty());
        $this->assertSame($correction, $result->getCorrection());
    }

    public function testAFacetIsFoundByItsAttributeId(): void
    {
        $result = new SearchResult(['a'], 1, [$this->facet('color'), $this->facet('size')]);

        $this->assertNotNull($result->getFacet('size'));
        $this->assertSame('size', $result->getFacet('size')->getAttributeId());
    }

    /**
     * Null rather than an exception: a template asking for a facet the engine
     * did not return should render nothing, not break the page.
     */
    public function testAnUnknownFacetIsNullRatherThanAnError(): void
    {
        $result = new SearchResult(['a'], 1, [$this->facet('color')]);

        $this->assertNull($result->getFacet('material'));
        $this->assertNull((new SearchResult([], 0))->getFacet('color'));
    }

    public function testActiveFiltersAreDetectedAcrossAllFacets(): void
    {
        $none = new SearchResult(['a'], 1, [$this->facet('color'), $this->facet('size')]);
        $one  = new SearchResult(['a'], 1, [$this->facet('color'), $this->facet('size', true)]);

        $this->assertFalse($none->hasActiveFilters());
        $this->assertTrue($one->hasActiveFilters(), 'a selection in any facet counts');
    }

    public function testTheDurationDefaultsToZeroAndIsKeptWhenGiven(): void
    {
        $this->assertSame(0.0, (new SearchResult([], 0))->getDuration());
        $this->assertSame(0.125, (new SearchResult([], 0, [], null, 0.125))->getDuration());
    }
}
