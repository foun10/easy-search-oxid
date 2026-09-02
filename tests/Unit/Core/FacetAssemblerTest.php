<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Core;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\AttributeTitles;
use foun10\EasySearch\Core\FacetAssembler;
use foun10\EasySearch\Core\FacetDisplay;
use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Engine\Query\FacetFilter;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Engine\Result\FacetValue;
use PHPUnit\Framework\TestCase;

/**
 * Assembles the sidebar both connectors render.
 *
 * This class is deliberately shared: counting differs between MySQL and
 * Meilisearch, but which facets appear, what they are called and when one is
 * worth showing is shop behaviour. That is also what makes the two engines
 * comparable - a difference in the sidebar is then a difference in the counts,
 * never in the assembly. So a bug here shows up identically on both backends
 * and looks like a data problem.
 *
 * The three collaborators all read the database, so they are stubbed; the
 * assembly logic itself is pure.
 */
class FacetAssemblerTest extends TestCase
{
    private const SHOP = 1;
    private const LANG = 0;

    /**
     * @param array<string, string> $customTitles
     * @param array<string, string> $displayModes
     * @param array<string, string> $titles
     */
    private function assembler(
        int $valueLimit = 30,
        array $customTitles = [],
        array $displayModes = [],
        array $titles = []
    ): FacetAssembler {
        $settings = $this->createMock(ModuleSettings::class);
        $settings->method('getFacetValueLimit')->willReturn($valueLimit);

        $configuration = $this->createMock(AttributeConfiguration::class);
        $configuration->method('getCustomTitles')->willReturn($customTitles);
        $configuration->method('getDisplayModes')->willReturn($displayModes);

        $attributeTitles = $this->createMock(AttributeTitles::class);
        $attributeTitles->method('get')->willReturn($titles);

        return new FacetAssembler($settings, $configuration, $attributeTitles);
    }

    private function query(FacetFilter ...$filters): SearchQuery
    {
        return new SearchQuery('bh', self::SHOP, self::LANG, $filters);
    }

    /**
     * @return array<int, array{valueId: string, value: string, count: int}>
     */
    private function counts(array ...$rows): array
    {
        return array_map(
            static fn (array $row): array => ['valueId' => $row[0], 'value' => $row[1], 'count' => $row[2]],
            $rows
        );
    }

    private function labelResolver(): callable
    {
        return static fn (string $attributeId, string $valueId, string $mode): string => 'label-' . $valueId;
    }

    public function testNoAttributesMeansNoFacets(): void
    {
        $facets = $this->assembler()->assemble($this->query(), [], [], $this->labelResolver());

        $this->assertSame([], $facets);
    }

    public function testAFacetIsBuiltFromItsCountedValues(): void
    {
        $facets = $this->assembler(titles: ['color' => 'Farbe'])->assemble(
            $this->query(),
            ['color'],
            ['color' => $this->counts(['v1', 'Rot', 4], ['v2', 'Blau', 2])],
            $this->labelResolver()
        );

        $this->assertCount(1, $facets);
        $this->assertSame('color', $facets[0]->getAttributeId());
        $this->assertSame('Farbe', $facets[0]->getTitle());
        $this->assertCount(2, $facets[0]->getValues());
        $this->assertSame('Rot', $facets[0]->getValues()[0]->getLabel());
        $this->assertSame(4, $facets[0]->getValues()[0]->getCount());
    }

    /**
     * A merchant's own label wins, because source systems name these things
     * "Farbcode_HEX" and that is not a word to put in front of a customer.
     */
    public function testAMerchantsOwnTitleWinsOverTheAttributeTitle(): void
    {
        $facets = $this->assembler(
            customTitles: ['color' => 'Farbe'],
            titles: ['color' => 'Farbcode_HEX']
        )->assemble(
            $this->query(),
            ['color'],
            ['color' => $this->counts(['v1', 'Rot', 4], ['v2', 'Blau', 2])],
            $this->labelResolver()
        );

        $this->assertSame('Farbe', $facets[0]->getTitle());
    }

    public function testTheAttributeIdIsTheLastResortTitle(): void
    {
        $facets = $this->assembler()->assemble(
            $this->query(),
            ['color'],
            ['color' => $this->counts(['v1', 'Rot', 4], ['v2', 'Blau', 2])],
            $this->labelResolver()
        );

        $this->assertSame('color', $facets[0]->getTitle());
    }

    /**
     * Only an attribute configured as a colour splits its values. Guessing by
     * shape used to turn any value ending in _#something into a colour tile.
     */
    public function testOnlyAColourFacetSplitsItsValues(): void
    {
        $counts = ['a' => $this->counts(['v1', 'Schwarz_#272727', 4], ['v2', 'Rot_#ff0000', 2])];

        $plain = $this->assembler()->assemble($this->query(), ['a'], $counts, $this->labelResolver());
        $color = $this->assembler(displayModes: ['a' => FacetDisplay::MODE_COLOR])
            ->assemble($this->query(), ['a'], $counts, $this->labelResolver());

        $this->assertSame('Schwarz_#272727', $plain[0]->getValues()[0]->getLabel(), 'kept verbatim');
        $this->assertNull($plain[0]->getValues()[0]->getHexCode());

        $this->assertSame('Schwarz', $color[0]->getValues()[0]->getLabel());
        $this->assertSame('#272727', $color[0]->getValues()[0]->getHexCode());
    }

    public function testTheFacetTypeFollowsTheConfiguredDisplayMode(): void
    {
        $counts = ['a' => $this->counts(['v1', 'Rot_#ff0000', 4], ['v2', 'Blau_#0000ff', 2])];

        $plain = $this->assembler()->assemble($this->query(), ['a'], $counts, $this->labelResolver());
        $color = $this->assembler(displayModes: ['a' => FacetDisplay::MODE_COLOR])
            ->assemble($this->query(), ['a'], $counts, $this->labelResolver());

        $this->assertSame(Facet::TYPE_LIST, $plain[0]->getType());
        $this->assertSame(Facet::TYPE_COLOR, $color[0]->getType());
    }

    public function testTheValueLimitCutsTheList(): void
    {
        $facets = $this->assembler(valueLimit: 2)->assemble(
            $this->query(),
            ['a'],
            ['a' => $this->counts(['v1', 'A', 5], ['v2', 'B', 4], ['v3', 'C', 3])],
            $this->labelResolver()
        );

        $this->assertCount(2, $facets[0]->getValues());
        $this->assertSame('A', $facets[0]->getValues()[0]->getLabel());
    }

    /**
     * The caller delivers values already ordered; re-sorting here would quietly
     * change ties, because PHP compares bytes where the database compares by
     * collation.
     */
    public function testTheGivenOrderIsPreserved(): void
    {
        $facets = $this->assembler()->assemble(
            $this->query(),
            ['a'],
            ['a' => $this->counts(['v1', 'Öl', 1], ['v2', 'Oel', 9], ['v3', 'Aal', 5])],
            $this->labelResolver()
        );

        $this->assertSame(
            ['Öl', 'Oel', 'Aal'],
            array_map(static fn (FacetValue $v): string => $v->getLabel(), $facets[0]->getValues())
        );
    }

    /**
     * A group offering one choice cannot narrow anything the customer is not
     * already looking at - it is a control that does nothing, and one that
     * costs a click to find that out.
     */
    public function testAFacetWithASingleValueIsNotWorthShowing(): void
    {
        $facets = $this->assembler()->assemble(
            $this->query(),
            ['a'],
            ['a' => $this->counts(['v1', 'Rot', 4])],
            $this->labelResolver()
        );

        $this->assertSame([], $facets);
    }

    public function testAFacetWithNoValuesAtAllIsNotShown(): void
    {
        $facets = $this->assembler()->assemble($this->query(), ['a'], [], $this->labelResolver());

        $this->assertSame([], $facets);
    }

    /**
     * Values that lead nowhere are not counted towards the minimum, since they
     * are not rendered - a facet whose values were all ruled out by another
     * selection would otherwise be a headline with nothing under it.
     */
    public function testValuesThatLeadNowhereDoNotKeepAFacetAlive(): void
    {
        $facets = $this->assembler()->assemble(
            $this->query(),
            ['a'],
            ['a' => $this->counts(['v1', 'Rot', 4], ['v2', 'Blau', 0], ['v3', 'Grün', 0])],
            $this->labelResolver()
        );

        $this->assertSame([], $facets, 'one reachable value is not enough');
    }

    /**
     * The exception that matters: a single value keeps its facet on screen when
     * it is the selected one. Hiding it would leave an active filter with no
     * way to switch it off.
     */
    public function testASingleValueFacetStaysWhenThatValueIsSelected(): void
    {
        $facets = $this->assembler()->assemble(
            $this->query(new FacetFilter('a', ['v1'])),
            ['a'],
            ['a' => $this->counts(['v1', 'Rot', 4])],
            $this->labelResolver()
        );

        $this->assertCount(1, $facets);
        $this->assertTrue($facets[0]->hasSelection());
    }

    /**
     * And the harder version of the same case: the selected value dropped out
     * of the result set entirely, so it is not in the counts at all. It still
     * has to be rendered, with a zero count, or the customer is stuck.
     */
    public function testASelectedValueMissingFromTheCountsIsAddedBack(): void
    {
        $facets = $this->assembler()->assemble(
            $this->query(new FacetFilter('a', ['gone'])),
            ['a'],
            ['a' => $this->counts(['v1', 'Rot', 4])],
            $this->labelResolver()
        );

        $this->assertCount(1, $facets);

        $selected = $facets[0]->getSelectedValues();
        $this->assertCount(1, $selected);
        $this->assertSame('gone', $selected[0]->getValueId());
        $this->assertSame(0, $selected[0]->getCount());
        $this->assertSame('label-gone', $selected[0]->getLabel(), 'the label comes from the resolver');
    }

    /**
     * Positions number the facets that are actually shown. If a hidden facet
     * consumed a position the sidebar would have gaps, and anything ordering by
     * position would sort around a slot nothing occupies.
     */
    public function testHiddenFacetsDoNotConsumeAPosition(): void
    {
        $facets = $this->assembler()->assemble(
            $this->query(),
            ['first', 'hidden', 'second'],
            [
                'first' => $this->counts(['v1', 'A', 2], ['v2', 'B', 1]),
                'hidden' => $this->counts(['v3', 'C', 1]),
                'second' => $this->counts(['v4', 'D', 3], ['v5', 'E', 2]),
            ],
            $this->labelResolver()
        );

        $this->assertCount(2, $facets);
        $this->assertSame(['first', 'second'], array_map(
            static fn (Facet $f): string => $f->getAttributeId(),
            $facets
        ));
        $this->assertSame([0, 1], array_map(static fn (Facet $f): int => $f->getPosition(), $facets));
    }

    public function testTheSelectionMapIsKeyedByAttribute(): void
    {
        $map = $this->assembler()->getSelectionMap(
            $this->query(new FacetFilter('color', ['v1', 'v2']), new FacetFilter('size', ['s38']))
        );

        $this->assertSame(['color' => ['v1', 'v2'], 'size' => ['s38']], $map);
    }

    public function testAQueryWithoutFiltersHasAnEmptySelectionMap(): void
    {
        $this->assertSame([], $this->assembler()->getSelectionMap($this->query()));
    }

    public function testDuplicateValueIdsInTheCountsAreNotRenderedTwice(): void
    {
        $facets = $this->assembler()->assemble(
            $this->query(new FacetFilter('a', ['v1'])),
            ['a'],
            ['a' => $this->counts(['v1', 'Rot', 4], ['v2', 'Blau', 2])],
            $this->labelResolver()
        );

        $ids = array_map(static fn (FacetValue $v): string => $v->getValueId(), $facets[0]->getValues());

        $this->assertSame($ids, array_unique($ids), 'the selected value must not be appended a second time');
    }
}
