<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Engine\Meili;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Correction\Normalizer;
use foun10\EasySearch\Engine\Meili\FilterBuilder;
use foun10\EasySearch\Engine\Meili\MeiliFacetBuilder;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Query\SuggestQuery;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Meili\MeiliException;
use foun10\EasySearch\Suggest\CategorySuggester;
use foun10\EasySearch\Suggest\DictionaryTerms;
use foun10\EasySearch\Tests\Unit\Double\SpyMeiliClient;
use foun10\EasySearch\Tests\Unit\Double\TestableMeiliConfiguration;
use foun10\EasySearch\Tests\Unit\Double\TestableMeiliSearchEngine;
use PHPUnit\Framework\TestCase;

/**
 * Searching through Meilisearch.
 *
 * The engine has to answer exactly what the MySql one answers - group IDs, a
 * total and the facet sidebar - so that nothing above it learns which engine
 * ran. What is worth pinning is therefore mostly the request it builds, plus
 * the three decisions that are specific to this store: variants are collapsed
 * with `distinct` rather than a GROUP BY, facets are counted by a query of
 * their own, and relevance is expressed by sending no sort at all.
 *
 * And one rule that outranks all of them: a search server that is down must
 * never take a customer's page with it.
 */
class MeiliSearchEngineTest extends TestCase
{
    private SpyMeiliClient $client;

    private TestableMeiliSearchEngine $engine;

    /** @var string[] */
    private array $filters = ['visible = true'];

    /** @var Facet[] */
    private array $facets = [];

    /** @var string[] */
    private array $dictionaryTerms = [];

    /** @var string[] */
    private array $categoryIds = [];

    private int $minTermLength = 2;

    private string $categorySuggesterSawTerm = '';

    private const SEARCH_PATH = '/indexes/foun10easysearch_s1_l0/search';

    protected function setUp(): void
    {
        $this->client = new SpyMeiliClient();

        $filterBuilder = $this->createMock(FilterBuilder::class);
        $filterBuilder->method('build')->willReturnCallback(fn (): array => $this->filters);

        $facetBuilder = $this->createMock(MeiliFacetBuilder::class);
        $facetBuilder->method('build')->willReturnCallback(fn (): array => $this->facets);

        $moduleSettings = $this->createMock(ModuleSettings::class);
        $moduleSettings->method('getMinTermLength')->willReturnCallback(fn (): int => $this->minTermLength);

        $dictionaryTerms = $this->createMock(DictionaryTerms::class);
        $dictionaryTerms->method('complete')->willReturnCallback(fn (): array => $this->dictionaryTerms);

        $categorySuggester = $this->createMock(CategorySuggester::class);
        $categorySuggester->method('suggest')->willReturnCallback(
            function (string $term, int $shopId, int $langId, int $limit): array {
                $this->categorySuggesterSawTerm = $term;

                return $this->categoryIds;
            }
        );

        $this->engine = new TestableMeiliSearchEngine(
            $this->client,
            new TestableMeiliConfiguration(),
            $filterBuilder,
            $facetBuilder,
            $moduleSettings,
            new Normalizer(),
            $dictionaryTerms,
            $categorySuggester
        );
    }

    private function query(
        string $term = 'jacke',
        int $shopId = 1,
        int $langId = 0,
        string $sort = SearchQuery::SORT_RELEVANCE,
        int $offset = 0,
        int $limit = 24
    ): SearchQuery {
        return new SearchQuery($term, $shopId, $langId, [], $sort, $offset, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $hits
     */
    private function serverReturns(array $hits, ?int $totalHits = null, string $path = self::SEARCH_PATH): void
    {
        $response = ['hits' => $hits];

        if ($totalHits !== null) {
            $response['totalHits'] = $totalHits;
        }

        $this->client->answers['POST ' . $path] = $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function sentParameters(string $path = self::SEARCH_PATH): array
    {
        return (array) $this->client->firstCallTo('POST', $path)['payload'];
    }

    // ---------------------------------------------------------------
    // the request a search makes
    // ---------------------------------------------------------------

    public function testTheSearchGoesToTheIndexOfItsScope(): void
    {
        $this->engine->search($this->query(shopId: 2, langId: 1));

        $this->assertSame(
            ['POST /indexes/foun10easysearch_s2_l1/search'],
            $this->client->trace()
        );
    }

    /**
     * Variants are collapsed by `distinct`, not by a GROUP BY - and only the
     * group ID is retrieved, because the contract is IDs and anything more
     * would ship a long description across the wire for every hit.
     */
    public function testTheSearchAsksForCollapsedIdsAndNothingElse(): void
    {
        $this->engine->search($this->query(term: 'jacke', limit: 24));

        $this->assertSame(
            [
                'q' => 'jacke',
                'filter' => ['visible = true'],
                'page' => 1,
                'hitsPerPage' => 24,
                'distinct' => 'groupId',
                'attributesToRetrieve' => ['groupId'],
            ],
            $this->sentParameters()
        );
    }

    /**
     * A distribution taken from a query that collapses variants counts the
     * representative variant only, so the counts are a query of their own.
     */
    public function testTheSearchAsksForNoFacets(): void
    {
        $this->engine->search($this->query());

        $this->assertArrayNotHasKey('facets', $this->sentParameters());
    }

    public function testTheFilterComesFromTheFilterBuilder(): void
    {
        $this->filters = ['visible = true', 'f_at-color IN ["v-red"]'];

        $this->engine->search($this->query());

        $this->assertSame($this->filters, $this->sentParameters()['filter']);
    }

    /**
     * Page mode rather than offset and limit, because it is the mode that
     * returns an exact total - the listing prints that number.
     *
     * @dataProvider pageProvider
     */
    public function testTheOffsetBecomesAPageNumber(int $offset, int $limit, int $expectedPage): void
    {
        $this->engine->search($this->query(offset: $offset, limit: $limit));

        $this->assertSame($expectedPage, $this->sentParameters()['page']);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public function pageProvider(): array
    {
        return [
            'the first page' => [0, 24, 1],
            'the second page' => [24, 24, 2],
            'the third page' => [48, 24, 3],
            // A partial offset still names the page it falls into rather than
            // shifting the window.
            'an offset between pages' => [30, 24, 2],
            // Two thirds into the second page is still the second page, so it
            // is rounded down rather than to the nearest.
            'an offset most of the way through a page' => [40, 24, 2],
            'a page size of one' => [5, 1, 6],
        ];
    }

    /**
     * A limit of zero would be a division by zero, and asking Meilisearch for
     * zero hits per page is an error on its side.
     */
    public function testAnEmptyPageSizeStillAsksForOneHit(): void
    {
        $this->engine->search($this->query(offset: 0, limit: 0));

        $this->assertSame(1, $this->sentParameters()['hitsPerPage']);
        $this->assertSame(1, $this->sentParameters()['page']);
    }

    /**
     * @dataProvider sortProvider
     */
    public function testTheSortIsTranslatedForTheEngine(string $sort, array $expected): void
    {
        $this->engine->search($this->query(sort: $sort));

        $this->assertSame($expected, $this->sentParameters()['sort'] ?? []);
    }

    /**
     * @return array<string, array{string, string[]}>
     */
    public function sortProvider(): array
    {
        return [
            'price ascending' => [SearchQuery::SORT_PRICE_ASC, ['price:asc']],
            'price descending' => [SearchQuery::SORT_PRICE_DESC, ['price:desc']],
            'title' => [SearchQuery::SORT_TITLE_ASC, ['title:asc']],
            'newest' => [SearchQuery::SORT_NEWEST, ['insertTimestamp:desc']],
            'bestseller' => [SearchQuery::SORT_BESTSELLER, ['soldAmount:desc']],
        ];
    }

    /**
     * Relevance is Meilisearch's own ranking. Sending a sort would override
     * the ranking rules rather than break their ties, so the parameter is left
     * out entirely.
     */
    public function testRelevanceSendsNoSortAtAll(): void
    {
        $this->engine->search($this->query(sort: SearchQuery::SORT_RELEVANCE));

        $this->assertArrayNotHasKey('sort', $this->sentParameters());
    }

    public function testAnUnknownSortIsTreatedAsRelevance(): void
    {
        $this->engine->search($this->query(sort: 'by_vibes'));

        $this->assertArrayNotHasKey('sort', $this->sentParameters());
    }

    // ---------------------------------------------------------------
    // what a search answers
    // ---------------------------------------------------------------

    public function testTheHitsBecomeProductIds(): void
    {
        $this->serverReturns([['groupId' => 'p-1'], ['groupId' => 'p-2']], 2);

        $result = $this->engine->search($this->query());

        $this->assertSame(['p-1', 'p-2'], $result->getProductIds());
        $this->assertSame(2, $result->getTotalCount());
    }

    /**
     * A document without a group ID cannot be loaded, and an empty ID in the
     * list would become an empty article in the listing.
     */
    public function testAHitWithoutAGroupIsDropped(): void
    {
        $this->serverReturns([['id' => 'doc-1'], ['groupId' => 'p-2'], ['groupId' => ''], ['groupId' => 'p-4']], 4);

        $this->assertSame(
            ['p-2', 'p-4'],
            $this->engine->search($this->query())->getProductIds(),
            'and what is left is a list again, not a list with holes'
        );
    }

    /**
     * `distinct` makes totalHits exact, which is what the listing paginates
     * on. The estimate is only read when the exact number is missing.
     */
    public function testTheExactTotalIsPreferredOverTheEstimate(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = [
            'hits' => [['groupId' => 'p-1']],
            'totalHits' => 42,
            'estimatedTotalHits' => 1000,
        ];

        $this->assertSame(42, $this->engine->search($this->query())->getTotalCount());
    }

    public function testTheEstimateIsUsedWhenThereIsNoExactTotal(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = [
            'hits' => [['groupId' => 'p-1']],
            'estimatedTotalHits' => 1000,
        ];

        $this->assertSame(1000, $this->engine->search($this->query())->getTotalCount());
    }

    public function testAnAnswerWithoutAnyTotalCountsAsNone(): void
    {
        $this->serverReturns([]);

        $this->assertSame(0, $this->engine->search($this->query())->getTotalCount());
    }

    public function testTheFacetsComeFromTheirOwnQuery(): void
    {
        $this->facets = [new Facet('at-color', 'Farbe', [])];

        $this->assertSame($this->facets, $this->engine->search($this->query())->getFacets());
    }

    public function testTheResultCarriesHowLongItTook(): void
    {
        $duration = $this->engine->search($this->query())->getDuration();

        $this->assertGreaterThan(0.0, $duration);
        $this->assertLessThan(1.0, $duration, 'the elapsed time, not a timestamp');
    }

    /**
     * The correction stays empty on a search: Meilisearch corrects typos
     * itself, and running the module's corrector as well would mean two
     * corrections fighting over one query.
     */
    public function testASearchIsNotCorrectedTwice(): void
    {
        $this->serverReturns([['groupId' => 'p-1']], 1);

        $this->assertNull($this->engine->search($this->query(term: 'jakce'))->getCorrection());
    }

    // ---------------------------------------------------------------
    // a server that is not there
    // ---------------------------------------------------------------

    /**
     * An unreachable engine returns an empty result and lets the caller decide
     * whether to fall back to the shop's own search. It must never be fatal
     * for a customer.
     */
    public function testAFailedSearchIsEmptyRatherThanFatal(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = new MeiliException('Connection refused', 0);

        $result = $this->engine->search($this->query());

        $this->assertSame([], $result->getProductIds());
        $this->assertSame(0, $result->getTotalCount());
    }

    public function testAFailedSearchIsLogged(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = new MeiliException('Connection refused', 0);

        $this->engine->search($this->query());

        $this->assertSame(['foun10EasySearch: Connection refused'], $this->engine->loggedErrors);
    }

    // ---------------------------------------------------------------
    // suggest
    // ---------------------------------------------------------------

    public function testAShortTermIsNotWorthARoundTrip(): void
    {
        $this->minTermLength = 3;

        $result = $this->engine->suggest(new SuggestQuery('ja', 1, 0));

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $this->client->calls);
    }

    /**
     * The configured length is the shortest term that still suggests, not the
     * first one that is refused.
     */
    public function testATermOfExactlyTheMinimumLengthIsSearchedFor(): void
    {
        $this->minTermLength = 2;

        $this->engine->suggest(new SuggestQuery('ja', 1, 0));

        $this->assertCount(1, $this->client->calls);
    }

    /**
     * The length is counted in characters. In bytes, a two letter term in a
     * non-Latin alphabet would pass a minimum it is actually below - and the
     * suggest box would fire on the first keystroke.
     */
    public function testTheMinimumLengthIsCountedInCharactersNotBytes(): void
    {
        $this->minTermLength = 3;

        $result = $this->engine->suggest(new SuggestQuery('жа', 1, 0));

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $this->client->calls);
    }

    /**
     * The term is normalised before it is searched for, the same way the
     * dictionary was written - "Büstenhalter!" and "buestenhalter" have to
     * find the same thing.
     */
    public function testTheTermIsNormalisedBeforeItIsSearchedFor(): void
    {
        $this->engine->suggest(new SuggestQuery('Büstenhalter!', 1, 0));

        $this->assertSame('buestenhalter', $this->sentParameters()['q']);
    }

    /**
     * The category suggester works on names as they are written, so it gets
     * the term the customer typed rather than the folded one.
     */
    public function testTheCategorySuggesterSeesTheTermAsTyped(): void
    {
        $this->engine->suggest(new SuggestQuery('Büstenhalter!', 1, 0));

        $this->assertSame('Büstenhalter!', $this->categorySuggesterSawTerm);
    }

    public function testSuggestAsksForOnePageOfCollapsedProducts(): void
    {
        $this->engine->suggest(new SuggestQuery('jacke', 1, 0, productLimit: 4));

        $this->assertSame(
            [
                'q' => 'jacke',
                'filter' => ['visible = true'],
                'page' => 1,
                'hitsPerPage' => 4,
                'distinct' => 'groupId',
                'attributesToRetrieve' => ['groupId'],
            ],
            $this->sentParameters()
        );
    }

    public function testSuggestStillAsksForOneProductWhenNoneWereAskedFor(): void
    {
        $this->engine->suggest(new SuggestQuery('jacke', 1, 0, productLimit: 0));

        $this->assertSame(1, $this->sentParameters()['hitsPerPage'], 'zero hits per page is an error');
    }

    public function testASuggestHitWithoutAGroupIsDropped(): void
    {
        $this->serverReturns([['id' => 'doc-1'], ['groupId' => 'p-2'], ['groupId' => 'p-3']], 3);

        $this->assertSame(
            ['p-2', 'p-3'],
            $this->engine->suggest(new SuggestQuery('jacke', 1, 0))->getProductIds()
        );
    }

    public function testSuggestPrefersTheExactTotalOverTheEstimate(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = [
            'hits' => [['groupId' => 'p-1']],
            'totalHits' => 42,
            'estimatedTotalHits' => 1000,
        ];

        $this->assertSame(42, $this->engine->suggest(new SuggestQuery('jacke', 1, 0))->getTotalCount());
    }

    public function testSuggestFallsBackToTheEstimate(): void
    {
        $this->client->answers['POST ' . self::SEARCH_PATH] = [
            'hits' => [['groupId' => 'p-1']],
            'estimatedTotalHits' => 1000,
        ];

        $this->assertSame(1000, $this->engine->suggest(new SuggestQuery('jacke', 1, 0))->getTotalCount());
    }

    public function testSuggestWithoutAnyTotalCountsNone(): void
    {
        $this->serverReturns([['groupId' => 'p-1']]);

        $this->assertSame(0, $this->engine->suggest(new SuggestQuery('jacke', 1, 0))->getTotalCount());
    }

    public function testSuggestCombinesTermsProductsAndCategories(): void
    {
        $this->dictionaryTerms = ['jacke', 'jacken'];
        $this->categoryIds = ['c-1'];
        $this->serverReturns([['groupId' => 'p-1'], ['groupId' => 'p-2']], 17);

        $result = $this->engine->suggest(new SuggestQuery('jacke', 1, 0));

        $this->assertSame(['jacke', 'jacken'], $result->getTerms());
        $this->assertSame(['p-1', 'p-2'], $result->getProductIds());
        $this->assertSame(['c-1'], $result->getCategoryIds());
        $this->assertSame(17, $result->getTotalCount());
    }

    /**
     * The dictionary and the categories come out of the shop's own tables, so
     * a search server that is down costs the products and nothing else.
     */
    public function testSuggestStillAnswersWhenTheServerIsDown(): void
    {
        $this->dictionaryTerms = ['jacke'];
        $this->categoryIds = ['c-1'];
        $this->client->answers['POST ' . self::SEARCH_PATH] = new MeiliException('Connection refused', 0);

        $result = $this->engine->suggest(new SuggestQuery('jacke', 1, 0));

        $this->assertSame(['jacke'], $result->getTerms());
        $this->assertSame(['c-1'], $result->getCategoryIds());
        $this->assertSame([], $result->getProductIds());
        $this->assertSame(0, $result->getTotalCount());
        $this->assertSame(['foun10EasySearch: Connection refused'], $this->engine->loggedErrors);
    }

    // ---------------------------------------------------------------
    // availability
    // ---------------------------------------------------------------

    public function testAnIndexWithDocumentsIsAvailable(): void
    {
        $this->client->answers['GET /indexes/foun10easysearch_s1_l0/stats'] = ['numberOfDocuments' => 12];

        $this->assertTrue($this->engine->isAvailable(1, 0));
    }

    /**
     * An index that exists but holds nothing would answer every search with
     * "no results" - the shop's own search is better than that.
     */
    public function testAnEmptyIndexIsNotAvailable(): void
    {
        $this->client->answers['GET /indexes/foun10easysearch_s1_l0/stats'] = ['numberOfDocuments' => 0];

        $this->assertFalse($this->engine->isAvailable(1, 0));
    }

    public function testAMissingIndexIsNotAvailable(): void
    {
        $this->client->answers['GET /indexes/foun10easysearch_s1_l0/stats'] =
            new MeiliException('Index not found', 404);

        $this->assertFalse($this->engine->isAvailable(1, 0));
    }

    /**
     * The shop asks this while rendering, and on a page that also searches it
     * would otherwise be a second round trip for an answer that cannot have
     * changed in between.
     */
    public function testTheAnswerIsRememberedForTheRequest(): void
    {
        $this->client->answers['GET /indexes/foun10easysearch_s1_l0/stats'] = ['numberOfDocuments' => 12];

        $this->engine->isAvailable(1, 0);
        $this->engine->isAvailable(1, 0);

        $this->assertCount(1, $this->client->calls);
    }

    public function testAFailedAnswerIsRememberedToo(): void
    {
        $this->client->answers['GET /indexes/foun10easysearch_s1_l0/stats'] =
            new MeiliException('Index not found', 404);

        $this->assertFalse($this->engine->isAvailable(1, 0));
        $this->assertFalse($this->engine->isAvailable(1, 0));
        $this->assertCount(1, $this->client->calls);
    }

    public function testEachScopeIsAskedAboutSeparately(): void
    {
        $this->client->answers['GET /indexes/foun10easysearch_s1_l0/stats'] = ['numberOfDocuments' => 12];

        $this->assertTrue($this->engine->isAvailable(1, 0));
        $this->assertFalse($this->engine->isAvailable(1, 1), 'another language, another index');
        $this->assertCount(2, $this->client->calls);
    }
}
