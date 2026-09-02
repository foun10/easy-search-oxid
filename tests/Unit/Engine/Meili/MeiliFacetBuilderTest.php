<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Engine\Meili;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\FacetAssembler;
use foun10\EasySearch\Core\FacetDisplay;
use foun10\EasySearch\Engine\Meili\FilterBuilder;
use foun10\EasySearch\Engine\Meili\MeiliFacetBuilder;
use foun10\EasySearch\Engine\Query\FacetFilter;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Meili\IndexSchema;
use foun10\EasySearch\Meili\MeiliException;
use foun10\EasySearch\Tests\Unit\Double\SpyMeiliClient;
use foun10\EasySearch\Tests\Unit\Double\TestableMeiliConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * Counting the filter sidebar out of Meilisearch facet distributions.
 *
 * The rule that shapes the whole class: a facet is counted with its own
 * selection removed, or picking "red" would show every other colour at zero
 * and the sidebar would stop offering the way back out. Meilisearch has no
 * per-facet exclusion, so it takes one search per selected facet plus one
 * shared search for all the rest - exactly the pattern the SQL side uses.
 *
 * The second rule is easy to lose and expensive: these counting searches must
 * not pass `distinct`. Riding along on the query that fetched the hits would
 * be one request cheaper and quietly wrong - with `distinct` in play
 * Meilisearch counts deduplicated documents, so a bra that comes in cups B to
 * E would count towards whichever cup its representative variant happens to
 * have and towards no other.
 *
 * Assembling the counted values into facets is FacetAssembler's job and is
 * tested there; here it is a collaborator, so what this file pins is the
 * queries, the decoding and the ordering.
 */
class MeiliFacetBuilderTest extends TestCase
{
    private SpyMeiliClient $client;

    private MeiliFacetBuilder $builder;

    /** @var array<int, string|int> */
    private array $facetAttributeIds = ['at-color', 'at-size'];

    /** @var array<string, string[]> */
    private array $selection = [];

    /** @var Facet[] */
    private array $facets = [];

    /** @var string[] */
    private array $assembledAttributeIds = [];

    /** @var array<string, array<int, array{valueId: string, value: string, count: int}>> */
    private array $assembledCounts = [];

    /** @var callable|null */
    private $labelResolver = null;

    private const SEARCH_PATH = '/indexes/foun10easysearch_s1_l0/search';

    protected function setUp(): void
    {
        $this->client = new SpyMeiliClient();

        $attributeConfiguration = $this->createMock(AttributeConfiguration::class);
        $attributeConfiguration->method('getFacetAttributeIds')
            ->willReturnCallback(fn (): array => $this->facetAttributeIds);

        $assembler = $this->createMock(FacetAssembler::class);
        $assembler->method('getSelectionMap')->willReturnCallback(fn (): array => $this->selection);
        $assembler->method('assemble')->willReturnCallback(
            function (SearchQuery $query, array $attributeIds, array $counts, callable $labelResolver): array {
                $this->assembledAttributeIds = $attributeIds;
                $this->assembledCounts = $counts;
                $this->labelResolver = $labelResolver;

                return $this->facets;
            }
        );

        $this->builder = new MeiliFacetBuilder(
            $this->client,
            new TestableMeiliConfiguration(),
            new FilterBuilder(),
            $attributeConfiguration,
            $assembler
        );
    }

    /**
     * @param FacetFilter[] $filters
     */
    private function query(array $filters = []): SearchQuery
    {
        return new SearchQuery('jacke', 1, 0, $filters);
    }

    private function encoded(string $valueId, string $label): string
    {
        return IndexSchema::encodeFacetValue($valueId, $label);
    }

    /**
     * Scripts what the counting searches get back.
     *
     * @param array<string, array<string, int>> $distribution Facet field => encoded value => count
     */
    private function serverCounts(array $distribution): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = ['facetDistribution' => $distribution];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchPayloads(): array
    {
        return array_map(
            static fn (array $call): array => (array) $call['payload'],
            $this->client->callsTo('POST', self::SEARCH_PATH)
        );
    }

    // ---------------------------------------------------------------
    // which searches are made
    // ---------------------------------------------------------------

    public function testAShopWithoutFacetsAsksNothing(): void
    {
        $this->facetAttributeIds = [];

        $this->assertSame([], $this->builder->build($this->query()));
        $this->assertSame([], $this->client->calls);
    }

    /**
     * Nothing selected means nothing to exclude, so all facets are counted by
     * one search.
     */
    public function testUnselectedFacetsShareOneCountingSearch(): void
    {
        $this->builder->build($this->query());

        $this->assertCount(1, $this->searchPayloads());
        $this->assertSame(['fl_at-color', 'fl_at-size'], $this->searchPayloads()[0]['facets']);
    }

    /**
     * No hits, only the distribution - and above all no `distinct`, which
     * would count deduplicated documents and attribute a product to one of its
     * variants' values only.
     */
    public function testACountingSearchAsksForCountsAndNothingElse(): void
    {
        $this->builder->build($this->query());

        $this->assertSame(
            [
                'q' => 'jacke',
                'filter' => ['visible = true'],
                'limit' => 0,
                'facets' => ['fl_at-color', 'fl_at-size'],
            ],
            $this->searchPayloads()[0]
        );
    }

    /**
     * The selected facet counts its own alternatives, so its own filter is
     * left out - while the other facets keep narrowing it.
     */
    public function testASelectedFacetIsCountedWithoutItsOwnFilter(): void
    {
        $this->selection = ['at-color' => ['v-red']];

        $this->builder->build($this->query([new FacetFilter('at-color', ['v-red'])]));

        $payloads = $this->searchPayloads();

        $this->assertCount(2, $payloads);
        $this->assertSame(['fl_at-size'], $payloads[0]['facets'], 'the shared search for the rest');
        $this->assertSame(
            ['visible = true', 'f_at-color IN ["v-red"]'],
            $payloads[0]['filter'],
            'which is narrowed by the selection'
        );
        $this->assertSame(['fl_at-color'], $payloads[1]['facets'], 'and one for the selected facet');
        $this->assertSame(
            ['visible = true'],
            $payloads[1]['filter'],
            'counted as if nothing was picked in it'
        );
    }

    /**
     * With every facet selected there is nothing left to share, so the shared
     * search is not made at all.
     */
    public function testWithEveryFacetSelectedThereIsNoSharedSearch(): void
    {
        $this->selection = ['at-color' => ['v-red'], 'at-size' => ['v-40']];

        $this->builder->build($this->query([
            new FacetFilter('at-color', ['v-red']),
            new FacetFilter('at-size', ['v-40']),
        ]));

        $payloads = $this->searchPayloads();

        $this->assertCount(2, $payloads);
        $this->assertSame(['fl_at-color'], $payloads[0]['facets']);
        $this->assertSame(['visible = true', 'f_at-size IN ["v-40"]'], $payloads[0]['filter']);
        $this->assertSame(['fl_at-size'], $payloads[1]['facets']);
        $this->assertSame(['visible = true', 'f_at-color IN ["v-red"]'], $payloads[1]['filter']);
    }

    /**
     * Each selected facet is counted by a search of its own, and every one of
     * those results has to end up in the sidebar - the second must not replace
     * the first.
     */
    public function testTheCountsOfEverySearchAreKept(): void
    {
        $this->selection = ['at-color' => ['v-red'], 'at-size' => ['v-40']];
        $this->client->answers['POST ' . self::SEARCH_PATH] = function (array $call): array {
            $field = (string) $call['payload']['facets'][0];

            return ['facetDistribution' => [$field => [$this->encoded('v-' . $field, $field) => 7]]];
        };

        $this->builder->build($this->query([
            new FacetFilter('at-color', ['v-red']),
            new FacetFilter('at-size', ['v-40']),
        ]));

        $this->assertSame(
            ['at-color' => 'fl_at-color', 'at-size' => 'fl_at-size'],
            array_map(
                static fn (array $values): string => $values[0]['value'],
                $this->assembledCounts
            )
        );
    }

    public function testTheCountingSearchesGoToTheIndexOfTheScope(): void
    {
        $this->builder->build(new SearchQuery('jacke', 2, 1));

        $this->assertSame(['POST /indexes/foun10easysearch_s2_l1/search'], $this->client->trace());
    }

    // ---------------------------------------------------------------
    // what comes back
    // ---------------------------------------------------------------

    public function testTheCountedValuesAreDecodedForTheAssembler(): void
    {
        $this->serverCounts([
            'fl_at-color' => [$this->encoded('v-red', 'Rot') => 12],
            'fl_at-size' => [$this->encoded('v-40', '40') => 3],
        ]);

        $this->builder->build($this->query());

        $this->assertSame(['at-color', 'at-size'], $this->assembledAttributeIds);
        $this->assertSame(
            [
                'at-color' => [['valueId' => 'v-red', 'value' => 'Rot', 'count' => 12]],
                'at-size' => [['valueId' => 'v-40', 'value' => '40', 'count' => 3]],
            ],
            $this->assembledCounts
        );
    }

    /**
     * Most hits first, ties alphabetical - the ordering the SQL engine gets
     * from its ORDER BY, so the sidebar reads the same on both engines.
     */
    public function testValuesAreOrderedByCountThenAlphabetically(): void
    {
        $this->serverCounts([
            'fl_at-color' => [
                $this->encoded('v-blue', 'Blau') => 5,
                $this->encoded('v-yellow', 'Gelb') => 9,
                $this->encoded('v-red', 'Rot') => 5,
                $this->encoded('v-amber', 'Amber') => 5,
            ],
        ]);

        $this->builder->build($this->query());

        $this->assertSame(
            ['Gelb', 'Amber', 'Blau', 'Rot'],
            array_column($this->assembledCounts['at-color'], 'value')
        );
    }

    /**
     * A facet nothing in the result set carries still has to reach the
     * assembler - it is the assembler that decides whether an empty facet is
     * shown or dropped.
     */
    public function testAFacetWithoutCountsIsStillHandedOver(): void
    {
        $this->serverCounts(['fl_at-color' => [$this->encoded('v-red', 'Rot') => 12]]);

        $this->builder->build($this->query());

        $this->assertSame([], $this->assembledCounts['at-size']);
    }

    /**
     * A sidebar is worth less than a result page: if the counting search
     * fails, the facets come back empty rather than the search failing.
     */
    public function testAFailedCountingSearchLeavesTheSidebarEmpty(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = new MeiliException('Connection refused', 0);

        $this->builder->build($this->query());

        $this->assertSame(['at-color' => [], 'at-size' => []], $this->assembledCounts);
    }

    public function testAnAnswerWithoutADistributionCountsNothing(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = ['hits' => []];

        $this->builder->build($this->query());

        $this->assertSame(['at-color' => [], 'at-size' => []], $this->assembledCounts);
    }

    public function testTheAssembledFacetsAreWhatTheBuilderReturns(): void
    {
        $this->facets = [new Facet('at-color', 'Farbe', [])];

        $this->assertSame($this->facets, $this->builder->build($this->query()));
    }

    // ---------------------------------------------------------------
    // the label of a value that dropped out of the result set
    // ---------------------------------------------------------------

    /**
     * A selected value can be missing from the counts - a customer picks red,
     * then adds a size no red item comes in. The filter has to keep its label,
     * and it is asked of the index rather than carried in the URL, so filter
     * links stay short.
     */
    public function testTheLabelOfAValueOutsideTheResultSetIsLookedUp(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = [
            'facetDistribution' => ['fl_at-color' => [$this->encoded('v-red', 'Rot') => 4]],
        ];

        $this->builder->build($this->query());
        $label = ($this->labelResolver)('at-color', 'v-red', FacetDisplay::MODE_DEFAULT);

        $this->assertSame('Rot', $label);

        // Through a variable, because end() takes its argument by reference and
        // PHP 8.3 made handing it a function result an error rather than a
        // warning - which is how this first failed, on CI and only there.
        $payloads = $this->searchPayloads();
        $lookup = end($payloads);

        $this->assertSame(
            [
                'q' => '',
                'filter' => ['f_at-color = "v-red"'],
                'limit' => 0,
                'facets' => ['fl_at-color'],
            ],
            $lookup,
            'narrowed to that one value, with no term and no hits'
        );
    }

    /**
     * A colour value is stored as "Name_#hexcode"; the sidebar shows the name.
     */
    public function testAColourLabelIsReducedToItsName(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = [
            'facetDistribution' => ['fl_at-color' => [$this->encoded('v-red', 'Rot_#D32F2F') => 4]],
        ];

        $this->builder->build($this->query());

        $this->assertSame('Rot', ($this->labelResolver)('at-color', 'v-red', FacetDisplay::MODE_COLOR));
        $this->assertSame(
            'Rot_#D32F2F',
            ($this->labelResolver)('at-color', 'v-red', FacetDisplay::MODE_DEFAULT),
            'anywhere else the stored value is the label'
        );
    }

    public function testTheRightValueIsPickedOutOfTheDistribution(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = [
            'facetDistribution' => [
                'fl_at-color' => [
                    $this->encoded('v-blue', 'Blau') => 2,
                    $this->encoded('v-red', 'Rot') => 4,
                ],
            ],
        ];

        $this->builder->build($this->query());

        $this->assertSame('Rot', ($this->labelResolver)('at-color', 'v-red', FacetDisplay::MODE_DEFAULT));
    }

    /**
     * A value that is not in the index at all - an old filter link, a value
     * removed from the catalogue - falls back to its ID rather than an empty
     * chip the customer cannot read or remove.
     */
    public function testAnUnknownValueFallsBackToItsId(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = [
            'facetDistribution' => ['fl_at-color' => [$this->encoded('v-blue', 'Blau') => 2]],
        ];

        $this->builder->build($this->query());

        $this->assertSame('v-gone', ($this->labelResolver)('at-color', 'v-gone', FacetDisplay::MODE_DEFAULT));
    }

    public function testAFailedLookupFallsBackToTheIdAsWell(): void
    {
        $this->builder->build($this->query());
        $this->client->answers['POST ' . self::SEARCH_PATH] = new MeiliException('Connection refused', 0);

        $this->assertSame('v-red', ($this->labelResolver)('at-color', 'v-red', FacetDisplay::MODE_DEFAULT));
    }

    /**
     * The value ID comes out of a URL, so it is quoted before it goes into a
     * filter expression.
     */
    public function testTheLookedUpValueIsQuoted(): void
    {
        $this->builder->build($this->query());
        ($this->labelResolver)('at-color', 'v"1', FacetDisplay::MODE_DEFAULT);

        $payloads = $this->searchPayloads();

        $this->assertSame(['f_at-color = "v\\"1"'], end($payloads)['filter']);
    }

    // ---------------------------------------------------------------
    // the fields a caller can ask for itself
    // ---------------------------------------------------------------

    public function testTheFacetFieldsOfAShopCanBeAskedForDirectly(): void
    {
        $this->assertSame(['fl_at-color', 'fl_at-size'], $this->builder->getFacetFields(1));
    }

    /**
     * A numeric attribute ID arrives as an int from the configuration.
     */
    public function testANumericAttributeIdBecomesAFieldToo(): void
    {
        $this->facetAttributeIds = [4711];

        $this->assertSame(['fl_4711'], $this->builder->getFacetFields(1));
    }

    public function testANumericAttributeIdIsAlsoCounted(): void
    {
        $this->facetAttributeIds = [4711];
        $this->serverCounts(['fl_4711' => [$this->encoded('v-red', 'Rot') => 2]]);

        $this->builder->build($this->query());

        $this->assertSame(
            ['4711' => [['valueId' => 'v-red', 'value' => 'Rot', 'count' => 2]]],
            $this->assembledCounts
        );
    }
}
