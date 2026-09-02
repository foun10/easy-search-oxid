<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Meili;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Correction\Normalizer;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Query\SuggestQuery;
use foun10\EasySearch\Engine\Result\SearchResult;
use foun10\EasySearch\Engine\Result\SuggestResult;
use foun10\EasySearch\Engine\SearchEngineInterface;
use foun10\EasySearch\Meili\MeiliClient;
use foun10\EasySearch\Meili\MeiliConfiguration;
use foun10\EasySearch\Meili\MeiliException;
use foun10\EasySearch\Suggest\CategorySuggester;
use foun10\EasySearch\Suggest\DictionaryTerms;
use OxidEsales\Eshop\Core\Registry;

/**
 * Search implementation on top of Meilisearch.
 *
 * Answers exactly what the MySql engine answers - group IDs, a total and the
 * facet sidebar - so the controllers, templates and the article loading below
 * them never learn which engine ran. Three things are done differently because
 * the store is different:
 *
 * Typo tolerance is Meilisearch's own. The module's SpellCorrector is not run
 * on a search: correcting a term before handing it to an engine that already
 * corrects would mean two corrections fighting over the same query. What the
 * corrector still does here is complete the suggest box, where it works on the
 * dictionary rather than on the result set.
 *
 * Collapsing variants to one hit per product is the `distinct` search
 * parameter, not a GROUP BY. It applies to the hits and to the total - and it
 * is precisely why the facet counts are a query of their own rather than a
 * field on this response; see MeiliFacetBuilder.
 *
 * Only the group ID is retrieved. The contract is IDs, and asking for less
 * document than that would be free while asking for more would ship a long
 * description across the wire for every hit.
 */
class MeiliSearchEngine implements SearchEngineInterface
{
    /**
     * @var array<string, bool>
     */
    protected array $availability = [];

    public function __construct(
        protected MeiliClient $client,
        protected MeiliConfiguration $configuration,
        protected FilterBuilder $filterBuilder,
        protected MeiliFacetBuilder $facetBuilder,
        protected ModuleSettings $moduleSettings,
        protected Normalizer $normalizer,
        protected DictionaryTerms $dictionaryTerms,
        protected CategorySuggester $categorySuggester
    ) {
    }

    public function search(SearchQuery $query): SearchResult
    {
        $start = microtime(true);

        $parameters = [
            'q' => $query->getTerm(),
            'filter' => $this->filterBuilder->build($query),
            'page' => $this->toPage($query),
            'hitsPerPage' => max(1, $query->getLimit()),
            'distinct' => 'groupId',
            'attributesToRetrieve' => ['groupId'],
        ];

        $sort = $this->buildSort($query);

        if ($sort !== []) {
            $parameters['sort'] = $sort;
        }

        // No facets on this request. They are counted separately, because a
        // distribution taken from a query that collapses variants counts the
        // representative variant only - see MeiliFacetBuilder.

        try {
            $response = $this->client->post($this->getSearchPath($query), $parameters);
        } catch (MeiliException $exception) {
            $this->logFailure($exception);

            return SearchResult::empty();
        }

        $productIds = [];

        foreach ((array) ($response['hits'] ?? []) as $hit) {
            $productIds[] = (string) ($hit['groupId'] ?? '');
        }

        return new SearchResult(
            array_values(array_filter($productIds)),
            (int) ($response['totalHits'] ?? $response['estimatedTotalHits'] ?? 0),
            $this->facetBuilder->build($query),
            null,
            microtime(true) - $start
        );
    }

    public function suggest(SuggestQuery $query): SuggestResult
    {
        $term = $this->normalizer->normalize($query->getTerm());

        if (mb_strlen($term) < $this->moduleSettings->getMinTermLength()) {
            return SuggestResult::empty();
        }

        $searchQuery = new SearchQuery(
            $term,
            $query->getShopId(),
            $query->getLangId(),
            [],
            SearchQuery::SORT_RELEVANCE,
            0,
            $query->getProductLimit()
        );

        $productIds = [];
        $totalCount = 0;

        try {
            $response = $this->client->post($this->getSearchPath($searchQuery), [
                'q' => $term,
                'filter' => $this->filterBuilder->build($searchQuery),
                'page' => 1,
                'hitsPerPage' => max(1, $query->getProductLimit()),
                'distinct' => 'groupId',
                'attributesToRetrieve' => ['groupId'],
            ]);

            foreach ((array) ($response['hits'] ?? []) as $hit) {
                $productIds[] = (string) ($hit['groupId'] ?? '');
            }

            $totalCount = (int) ($response['totalHits'] ?? $response['estimatedTotalHits'] ?? 0);
        } catch (MeiliException $exception) {
            $this->logFailure($exception);
        }

        return new SuggestResult(
            $this->dictionaryTerms->complete($term, $query->getShopId(), $query->getLangId(), $query->getTermLimit()),
            array_values(array_filter($productIds)),
            $this->categorySuggester->suggest(
                $query->getTerm(),
                $query->getShopId(),
                $query->getLangId(),
                $query->getCategoryLimit()
            ),
            [],
            $totalCount
        );
    }

    /**
     * Is the index there and does it hold anything?
     *
     * Memoised for the request: the shop asks this while rendering, and on a
     * page that also searches it would otherwise be a second round trip for an
     * answer that cannot have changed in between.
     */
    public function isAvailable(int $shopId, int $langId): bool
    {
        $uid = $this->configuration->getIndexUid($shopId, $langId);

        if (isset($this->availability[$uid])) {
            return $this->availability[$uid];
        }

        try {
            $stats = $this->client->get('/indexes/' . $uid . '/stats');
        } catch (MeiliException $exception) {
            // No index yet, or no Meilisearch at all - the caller falls back
            // to the shop's stock search.
            return $this->availability[$uid] = false;
        }

        return $this->availability[$uid] = (int) ($stats['numberOfDocuments'] ?? 0) > 0;
    }

    /**
     * Relevance is Meilisearch's own ranking, so it is expressed by sending no
     * sort at all - a sort would override the ranking rules rather than break
     * their ties.
     *
     * @return string[]
     */
    protected function buildSort(SearchQuery $query): array
    {
        switch ($query->getSort()) {
            case SearchQuery::SORT_PRICE_ASC:
                return ['price:asc'];

            case SearchQuery::SORT_PRICE_DESC:
                return ['price:desc'];

            case SearchQuery::SORT_TITLE_ASC:
                return ['title:asc'];

            case SearchQuery::SORT_NEWEST:
                return ['insertTimestamp:desc'];

            case SearchQuery::SORT_BESTSELLER:
                return ['soldAmount:desc'];

            case SearchQuery::SORT_RELEVANCE:
            default:
                return [];
        }
    }

    /**
     * Page mode rather than offset/limit, because it is the mode that returns
     * an exact totalHits - the listing prints that number and paginates on it.
     */
    protected function toPage(SearchQuery $query): int
    {
        $limit = max(1, $query->getLimit());

        return (int) floor($query->getOffset() / $limit) + 1;
    }

    protected function getSearchPath(SearchQuery $query): string
    {
        return '/indexes/'
            . $this->configuration->getIndexUid($query->getShopId(), $query->getLangId())
            . '/search';
    }

    protected function logFailure(MeiliException $exception): void
    {
        // Never fatal for a customer: an unreachable engine returns an empty
        // result, and the caller decides whether to fall back. It must not pass
        // unnoticed either.
        $this->logError('foun10EasySearch: ' . $exception->getMessage());
    }

    /**
     * The only line in this class that touches the shop. Everything else it
     * needs arrives through the constructor, which is what lets the search
     * parameters, the sorting and the fallback behaviour be checked without
     * either a shop or a search server.
     */
    protected function logError(string $message): void
    {
        Registry::getLogger()->error($message);
    }
}
