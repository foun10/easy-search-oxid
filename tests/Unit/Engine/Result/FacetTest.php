<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Engine\Result;

use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Engine\Result\FacetValue;
use PHPUnit\Framework\TestCase;

/**
 * A filter group on the result page.
 *
 * The selection helpers decide what the sidebar shows as active. Getting them
 * wrong does not throw - it just stops the customer seeing which filter is on,
 * and therefore stops them switching it off again.
 */
class FacetTest extends TestCase
{
    private function facet(FacetValue ...$values): Facet
    {
        return new Facet('attr-1', 'Farbe', $values, Facet::TYPE_COLOR, 3);
    }

    public function testTheTypesAreStable(): void
    {
        $this->assertSame('list', Facet::TYPE_LIST);
        $this->assertSame('color', Facet::TYPE_COLOR);
        $this->assertSame('range', Facet::TYPE_RANGE);
    }

    public function testItCarriesItsIdentityAndPosition(): void
    {
        $facet = $this->facet(new FacetValue('v1', 'Rot', 4));

        $this->assertSame('attr-1', $facet->getAttributeId());
        $this->assertSame('Farbe', $facet->getTitle());
        $this->assertSame(Facet::TYPE_COLOR, $facet->getType());
        $this->assertSame(3, $facet->getPosition());
        $this->assertCount(1, $facet->getValues());
    }

    public function testAFacetWithoutValuesIsEmpty(): void
    {
        $this->assertTrue($this->facet()->isEmpty());
        $this->assertFalse($this->facet(new FacetValue('v1', 'Rot', 4))->isEmpty());
    }

    public function testNoSelectionIsReportedWhenNothingIsSelected(): void
    {
        $facet = $this->facet(
            new FacetValue('v1', 'Rot', 4),
            new FacetValue('v2', 'Blau', 2)
        );

        $this->assertFalse($facet->hasSelection());
        $this->assertSame([], $facet->getSelectedValues());
    }

    public function testASingleSelectedValueIsFound(): void
    {
        $facet = $this->facet(
            new FacetValue('v1', 'Rot', 4),
            new FacetValue('v2', 'Blau', 2, true)
        );

        $this->assertTrue($facet->hasSelection());
        $this->assertCount(1, $facet->getSelectedValues());
        $this->assertSame('v2', $facet->getSelectedValues()[0]->getValueId());
    }

    public function testSeveralSelectedValuesAreAllReturned(): void
    {
        $facet = $this->facet(
            new FacetValue('v1', 'Rot', 4, true),
            new FacetValue('v2', 'Blau', 2),
            new FacetValue('v3', 'Grün', 1, true)
        );

        $this->assertSame(
            ['v1', 'v3'],
            array_map(static fn (FacetValue $v): string => $v->getValueId(), $facet->getSelectedValues())
        );
    }

    /**
     * The filtered result is re-indexed, because these arrays are handed to
     * templates and to json_encode - a gapped array silently becomes a JSON
     * object and breaks whatever iterates it.
     */
    public function testTheSelectedValuesAreAListNotAGappedArray(): void
    {
        $facet = $this->facet(
            new FacetValue('v1', 'Rot', 4),
            new FacetValue('v2', 'Blau', 2, true)
        );

        $this->assertSame([0], array_keys($facet->getSelectedValues()));
    }

    /**
     * A selected value that dropped out of the result set still has to render,
     * or the customer cannot switch it off. It arrives with a zero count, and
     * that must not be mistaken for "not selected".
     */
    public function testASelectedValueWithoutHitsStillCountsAsSelected(): void
    {
        $facet = $this->facet(new FacetValue('v1', 'Rot', 0, true));

        $this->assertTrue($facet->hasSelection());
        $this->assertFalse($facet->isEmpty());
        $this->assertSame(0, $facet->getSelectedValues()[0]->getCount());
    }
}
