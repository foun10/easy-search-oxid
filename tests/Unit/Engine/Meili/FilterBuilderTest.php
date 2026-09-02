<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Engine\Meili;

use foun10\EasySearch\Engine\Meili\FilterBuilder;
use foun10\EasySearch\Engine\Query\FacetFilter;
use foun10\EasySearch\Engine\Query\SearchQuery;
use PHPUnit\Framework\TestCase;

/**
 * Narrowing a Meilisearch query.
 *
 * The counterpart of the MySql ConditionBuilder and a far smaller one - the
 * term goes to Meilisearch as the term, so what is left here is visibility,
 * category, manufacturer, price and the facets.
 *
 * Two rules carry everything else: the list is AND-combined by Meilisearch,
 * and the values within one facet are OR-combined through IN. Colour red OR
 * blue, but colour AND size - the same rule the SQL side applies, and the one
 * customers know from other shops.
 *
 * The class builds an expression out of values that come from a URL, so the
 * quoting is not cosmetic: a value carrying a quote would end the expression
 * early, and a decimal comma would turn one number into two operands.
 */
class FilterBuilderTest extends TestCase
{
    private FilterBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FilterBuilder();
    }

    /**
     * @param FacetFilter[] $filters
     */
    private function query(
        array $filters = [],
        ?string $categoryId = null,
        ?string $manufacturerId = null,
        ?float $priceFrom = null,
        ?float $priceTo = null
    ): SearchQuery {
        return new SearchQuery(
            'jacke',
            1,
            0,
            $filters,
            SearchQuery::SORT_RELEVANCE,
            0,
            24,
            $categoryId,
            $manufacturerId,
            $priceFrom,
            $priceTo
        );
    }

    // ---------------------------------------------------------------
    // what is always there
    // ---------------------------------------------------------------

    /**
     * Visibility is decided once at index time - it already carries "active"
     * and the shop's stock rule - so every query narrows on the one field
     * rather than repeating the rules.
     */
    public function testEveryQueryIsNarrowedToVisibleDocuments(): void
    {
        $this->assertSame(['visible = true'], $this->builder->build($this->query()));
    }

    // ---------------------------------------------------------------
    // the listing pages
    // ---------------------------------------------------------------

    public function testACategoryListingFiltersOnItsCategory(): void
    {
        $this->assertSame(
            ['visible = true', 'categoryIds = "c-1"'],
            $this->builder->build($this->query(categoryId: 'c-1'))
        );
    }

    public function testAManufacturerListingFiltersOnItsManufacturer(): void
    {
        $this->assertSame(
            ['visible = true', 'manufacturerId = "m-1"'],
            $this->builder->build($this->query(manufacturerId: 'm-1'))
        );
    }

    public function testAPriceRangeBecomesTwoBounds(): void
    {
        $this->assertSame(
            ['visible = true', 'price >= 19.99', 'price <= 250'],
            $this->builder->build($this->query(priceFrom: 19.99, priceTo: 250.0))
        );
    }

    public function testEachBoundStandsOnItsOwn(): void
    {
        $this->assertSame(
            ['visible = true', 'price >= 19.99'],
            $this->builder->build($this->query(priceFrom: 19.99))
        );
        $this->assertSame(
            ['visible = true', 'price <= 250'],
            $this->builder->build($this->query(priceTo: 250.0))
        );
    }

    /**
     * Free is a price, and so is a range that ends at zero - neither may be
     * dropped as if it had not been asked for.
     */
    public function testAZeroBoundIsStillABound(): void
    {
        $this->assertSame(
            ['visible = true', 'price >= 0', 'price <= 0'],
            $this->builder->build($this->query(priceFrom: 0.0, priceTo: 0.0))
        );
    }

    // ---------------------------------------------------------------
    // facets
    // ---------------------------------------------------------------

    /**
     * Several values of one facet are OR-combined through IN, different facets
     * are separate entries and therefore AND-combined.
     */
    public function testValuesOfOneFacetAreOredAndFacetsAreAnded(): void
    {
        $filters = [
            new FacetFilter('at-color', ['v-red', 'v-blue']),
            new FacetFilter('at-size', ['v-40']),
        ];

        $this->assertSame(
            [
                'visible = true',
                'f_at-color IN ["v-red", "v-blue"]',
                'f_at-size IN ["v-40"]',
            ],
            $this->builder->build($this->query($filters))
        );
    }

    /**
     * An empty selection is no selection. Written out it would be
     * `IN []`, which matches nothing and would empty the listing.
     */
    public function testAFacetWithoutValuesIsLeftOut(): void
    {
        $filters = [new FacetFilter('at-color', []), new FacetFilter('at-size', ['v-40'])];

        $this->assertSame(
            ['visible = true', 'f_at-size IN ["v-40"]'],
            $this->builder->build($this->query($filters))
        );
    }

    /**
     * A facet's own hit counts have to be taken without its own filter -
     * otherwise every colour but the selected one would count zero, and the
     * sidebar would stop offering the way back out.
     */
    public function testAFacetCanBeLeftOutForItsOwnCounts(): void
    {
        $filters = [
            new FacetFilter('at-color', ['v-red']),
            new FacetFilter('at-size', ['v-40']),
        ];

        $this->assertSame(
            ['visible = true', 'f_at-size IN ["v-40"]'],
            $this->builder->build($this->query($filters), 'at-color')
        );
    }

    /**
     * Only that one facet: the others still narrow the counts, which is what
     * makes "size 40 in red" show how many blue ones there would be.
     */
    public function testTheOtherFacetsStillNarrowTheCounts(): void
    {
        $filters = [
            new FacetFilter('at-color', ['v-red']),
            new FacetFilter('at-size', ['v-40']),
        ];

        $this->assertSame(
            ['visible = true', 'f_at-color IN ["v-red"]'],
            $this->builder->build($this->query($filters), 'at-size')
        );
    }

    public function testExcludingAFacetThatWasNotSelectedChangesNothing(): void
    {
        $filters = [new FacetFilter('at-color', ['v-red'])];

        $this->assertSame(
            ['visible = true', 'f_at-color IN ["v-red"]'],
            $this->builder->build($this->query($filters), 'at-material')
        );
    }

    /**
     * The everything-at-once case, in the order the expression is assembled.
     */
    public function testTheWholeNarrowingOfAFilteredCategoryPage(): void
    {
        $query = $this->query(
            [new FacetFilter('at-color', ['v-red'])],
            categoryId: 'c-1',
            manufacturerId: 'm-1',
            priceFrom: 10.0,
            priceTo: 50.0
        );

        $this->assertSame(
            [
                'visible = true',
                'categoryIds = "c-1"',
                'manufacturerId = "m-1"',
                'price >= 10',
                'price <= 50',
                'f_at-color IN ["v-red"]',
            ],
            $this->builder->build($query)
        );
    }

    // ---------------------------------------------------------------
    // quoting
    // ---------------------------------------------------------------

    /**
     * Meilisearch reads a filter as an expression, and these values arrive
     * from a URL. A bare quote would end the string early and leave the rest
     * of the value being read as filter syntax.
     */
    public function testAQuoteInsideAValueIsEscaped(): void
    {
        $this->assertSame('"say \\"hi\\""', $this->builder->quote('say "hi"'));
    }

    public function testABackslashIsEscapedBeforeTheQuotes(): void
    {
        $this->assertSame('"back\\\\slash"', $this->builder->quote('back\\slash'));
    }

    /**
     * The order matters: escaping the quote first and the backslash after
     * would double the backslash the escaping had just added, leaving the
     * quote unescaped again.
     */
    public function testABackslashInFrontOfAQuoteStaysHarmless(): void
    {
        $this->assertSame('"a\\\\\\"b"', $this->builder->quote('a\\"b'));
    }

    public function testAnEmptyValueIsStillQuoted(): void
    {
        $this->assertSame('""', $this->builder->quote(''));
    }

    public function testValuesFromTheUrlAreQuotedWhereverTheyAppear(): void
    {
        $query = $this->query(
            [new FacetFilter('at-color', ['v"1'])],
            categoryId: 'c"1',
            manufacturerId: 'm"1'
        );

        $this->assertSame(
            [
                'visible = true',
                'categoryIds = "c\\"1"',
                'manufacturerId = "m\\"1"',
                'f_at-color IN ["v\\"1"]',
            ],
            $this->builder->build($query)
        );
    }

    // ---------------------------------------------------------------
    // numbers
    // ---------------------------------------------------------------

    /**
     * @dataProvider numberProvider
     */
    public function testHowAPriceIsWritten(float $price, string $expected): void
    {
        $this->assertSame(
            ['visible = true', 'price >= ' . $expected],
            $this->builder->build($this->query(priceFrom: $price))
        );
    }

    /**
     * @return array<string, array{float, string}>
     */
    public function numberProvider(): array
    {
        return [
            'a normal price' => [19.99, '19.99'],
            // Trailing zeros are trimmed, and so is the point they leave
            // behind - "20." is not a number Meilisearch accepts.
            'a round price' => [20.0, '20'],
            'one decimal' => [1000.5, '1000.5'],
            'zero' => [0.0, '0'],
            'negative' => [-5.0, '-5'],
            // Four decimals is the precision; below that a bound is zero
            // rather than an empty operand.
            'far below the precision' => [0.00001, '0'],
            'at the precision' => [0.0001, '0.0001'],
            // No thousands separator: it would be read as an argument list.
            'a four figure price' => [12345.5, '12345.5'],
        ];
    }

    /**
     * A decimal comma would turn one number into two operands - which is why
     * the separators are given explicitly rather than left to the locale.
     */
    public function testAPriceNeverCarriesAComma(): void
    {
        $filters = $this->builder->build($this->query(priceFrom: 1234.56, priceTo: 9876.54));

        $this->assertSame(['visible = true', 'price >= 1234.56', 'price <= 9876.54'], $filters);
    }
}
