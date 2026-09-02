<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\MySql;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Correction\Normalizer;
use foun10\EasySearch\Correction\SpellCorrector;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Query\SuggestQuery;
use foun10\EasySearch\Engine\Result\Correction;
use foun10\EasySearch\Engine\Result\SearchResult;
use foun10\EasySearch\Engine\Result\SuggestResult;
use foun10\EasySearch\Engine\SearchEngineInterface;
use foun10\EasySearch\Index\DictionaryBuilder;
use foun10\EasySearch\Index\MySql\IndexTables;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\TableViewNameGenerator;

/**
 * Search implementation on top of MariaDB fulltext indexes.
 *
 * The correction pass deserves a note. Searching twice for a query that found
 * nothing is cheap - the first query returned no rows, so nothing was scanned
 * for real. Searching twice for a query that found plenty would be wasteful,
 * which is why the correction only runs at or below
 * FOUN10EASYSEARCH_CORRECTION_MAX_HITS.
 *
 * A correction is only applied when it genuinely does better than the original.
 * Auto-applying a correction that finds fewer products than the customer's own
 * spelling is worse than doing nothing.
 */
class MySqlSearchEngine implements SearchEngineInterface
{
    public function __construct(
        protected ConditionBuilder $conditionBuilder,
        protected FacetBuilder $facetBuilder,
        protected SpellCorrector $spellCorrector,
        protected Normalizer $normalizer,
        protected ModuleSettings $moduleSettings,
        protected IndexTables $tables
    ) {
    }

    public function search(SearchQuery $query): SearchResult
    {
        $start = microtime(true);

        $totalCount = $this->countGroups($query);
        $correction = null;

        if ($this->shouldCorrect($query, $totalCount)) {
            $result = $this->searchCorrected($query, $totalCount, $start);

            if ($result !== null) {
                return $result;
            }
        }

        return new SearchResult(
            $totalCount > 0 ? $this->fetchGroupIds($query) : [],
            $totalCount,
            $this->facetBuilder->build($query),
            $correction,
            microtime(true) - $start
        );
    }

    /**
     * Second pass with a corrected term. Returns null when correction did not
     * improve anything, so the caller keeps the original result.
     */
    protected function searchCorrected(SearchQuery $query, int $originalCount, float $start): ?SearchResult
    {
        $corrected = $this->spellCorrector->correct(
            $query->getTerm(),
            $query->getShopId(),
            $query->getLangId()
        );

        if ($corrected === null || !$corrected->hasChanged()) {
            return null;
        }

        $correctedQuery = $query->withCorrectedTerm($corrected->getCorrected());
        $correctedCount = $this->countGroups($correctedQuery);

        if ($correctedCount <= $originalCount) {
            return null;
        }

        // With auto-apply switched off the customer keeps their own result set
        // and only gets an offer.
        if (!$this->moduleSettings->isCorrectionAutoApplied()) {
            return new SearchResult(
                $originalCount > 0 ? $this->fetchGroupIds($query) : [],
                $originalCount,
                $this->facetBuilder->build($query),
                Correction::suggested(
                    $query->getTerm(),
                    $corrected->getCorrected(),
                    $corrected->getMaxDistance(),
                    $correctedCount
                ),
                microtime(true) - $start
            );
        }

        return new SearchResult(
            $this->fetchGroupIds($correctedQuery),
            $correctedCount,
            $this->facetBuilder->build($correctedQuery),
            Correction::applied(
                $query->getTerm(),
                $corrected->getCorrected(),
                $corrected->getMaxDistance(),
                $correctedCount
            ),
            microtime(true) - $start
        );
    }

    protected function shouldCorrect(SearchQuery $query, int $totalCount): bool
    {
        return $query->hasTerm()
            && $query->isCorrectionAllowed()
            && $this->moduleSettings->isCorrectionEnabled()
            && $totalCount <= $this->moduleSettings->getCorrectionMaxHits();
    }

    protected function countGroups(SearchQuery $query): int
    {
        $conditions = $this->conditionBuilder->build($query);

        $sql = '
            SELECT COUNT(DISTINCT i.FOUN10GROUPID)
            FROM ' . $this->tables->index($query->getShopId()) . ' AS i
            WHERE ' . $conditions->where;

        return (int) DatabaseProvider::getDb()->getOne($sql, $conditions->parameters);
    }

    /**
     * @return string[]
     */
    protected function fetchGroupIds(SearchQuery $query): array
    {
        $conditions = $this->conditionBuilder->build($query);

        $sql = '
            SELECT i.FOUN10GROUPID
            FROM ' . $this->tables->index($query->getShopId()) . ' AS i
            WHERE ' . $conditions->where . '
            GROUP BY i.FOUN10GROUPID
            ORDER BY ' . $this->buildOrderBy($query, $conditions) . '
            LIMIT ' . $query->getOffset() . ', ' . $query->getLimit();

        return (array) DatabaseProvider::getDb()->getCol($sql, $conditions->parameters);
    }

    /**
     * Every expression aggregates, because rows are collapsed to one per
     * product and a variant's price or stock must not leak into the ordering
     * of the whole group.
     */
    protected function buildOrderBy(SearchQuery $query, SearchConditions $conditions): string
    {
        switch ($query->getSort()) {
            case SearchQuery::SORT_PRICE_ASC:
                return 'MIN(i.FOUN10PRICE) ASC';

            case SearchQuery::SORT_PRICE_DESC:
                return 'MAX(i.FOUN10PRICE) DESC';

            case SearchQuery::SORT_TITLE_ASC:
                return 'MIN(i.FOUN10TITLE) ASC';

            case SearchQuery::SORT_NEWEST:
                return 'MAX(i.FOUN10INSERTDATE) DESC';

            case SearchQuery::SORT_BESTSELLER:
                return 'MAX(i.FOUN10SOLDAMOUNT) DESC';

            case SearchQuery::SORT_RELEVANCE:
            default:
                if (!$conditions->hasRelevance()) {
                    // Category pages and short-term searches have no fulltext
                    // score to rank by.
                    return 'MAX(i.FOUN10SOLDAMOUNT) DESC, MIN(i.FOUN10TITLE) ASC';
                }

                return 'MAX' . $conditions->relevanceExpression . ' DESC, MAX(i.FOUN10SOLDAMOUNT) DESC';
        }
    }

    public function suggest(SuggestQuery $query): SuggestResult
    {
        $term = $this->normalizer->normalize($query->getTerm());

        if (mb_strlen($term) < $this->moduleSettings->getMinTermLength()) {
            return SuggestResult::empty();
        }

        $searchQuery = new SearchQuery($term, $query->getShopId(), $query->getLangId());

        return new SuggestResult(
            $this->suggestTerms($term, $query),
            $this->suggestProducts($term, $query),
            $this->suggestCategories($query),
            [],
            $this->countGroups($searchQuery)
        );
    }

    /**
     * Prefix match against the dictionary, ordered by catalogue frequency.
     *
     * If the prefix matches nothing the term is run through the corrector
     * first, so a customer who mistyped early in the word still gets
     * suggestions instead of an empty dropdown.
     *
     * @return string[]
     */
    protected function suggestTerms(string $term, SuggestQuery $query): array
    {
        $terms = $this->fetchTermsByPrefix($term, $query);

        if ($terms !== []) {
            return $terms;
        }

        $corrected = $this->spellCorrector->correct($term, $query->getShopId(), $query->getLangId());

        if ($corrected === null || !$corrected->hasChanged()) {
            return [];
        }

        return $this->fetchTermsByPrefix($corrected->getCorrected(), $query);
    }

    /**
     * @return string[]
     */
    protected function fetchTermsByPrefix(string $prefix, SuggestQuery $query): array
    {
        $database = DatabaseProvider::getDb();

        $sql = '
            SELECT FOUN10TERMRAW
            FROM ' . DictionaryBuilder::TABLE . '
            WHERE OXSHOPID = :shopId
                AND FOUN10LANGID = :langId
                AND FOUN10TERM LIKE ' . $database->quote($prefix . '%') . '
            ORDER BY FOUN10FREQUENCY DESC
            LIMIT ' . $query->getTermLimit();

        return (array) $database->getCol($sql, [
            ':shopId' => $query->getShopId(),
            ':langId' => $query->getLangId(),
        ]);
    }

    /**
     * Product suggestions, as IDs only.
     *
     * Same contract as search(): the controller loads the articles and renders
     * them. Six articles on a debounced keystroke is a cheap query, and it
     * keeps prices, pictures and links correct instead of frozen at the last
     * reindex.
     *
     * @return string[]
     */
    protected function suggestProducts(string $term, SuggestQuery $query): array
    {
        $searchQuery = new SearchQuery(
            $term,
            $query->getShopId(),
            $query->getLangId(),
            [],
            SearchQuery::SORT_RELEVANCE,
            0,
            $query->getProductLimit()
        );

        $conditions = $this->conditionBuilder->build($searchQuery);

        $sql = '
            SELECT i.FOUN10GROUPID
            FROM ' . $this->tables->index($query->getShopId()) . ' AS i
            WHERE ' . $conditions->where . '
            GROUP BY i.FOUN10GROUPID
            ORDER BY ' . $this->buildOrderBy($searchQuery, $conditions) . '
            LIMIT ' . $query->getProductLimit();

        return (array) DatabaseProvider::getDb()->getCol($sql, $conditions->parameters);
    }

    /**
     * Categories whose name contains the typed text.
     *
     * Matched against oxcategories rather than the search index on purpose:
     * the question is whether the customer is naming a category, not whether
     * some product in it matches. A shop has a few hundred categories, so a
     * LIKE over the view is cheap.
     *
     * Matched on the raw input, because category titles are stored as written
     * ("Waesche" never matches "Wäsche" the other way round). Prefix hits sort
     * first, then the shortest title - "BHs" should beat "BHs und Bustiers".
     *
     * @return string[]
     */
    protected function suggestCategories(SuggestQuery $query): array
    {
        $limit = $query->getCategoryLimit();

        if ($limit < 1) {
            return [];
        }

        $database = DatabaseProvider::getDb();
        $categoryView = Registry::get(TableViewNameGenerator::class)
            ->getViewName('oxcategories', $query->getLangId(), $query->getShopId());

        $term = trim($query->getTerm());

        if ($term === '') {
            return [];
        }

        $quotedContains = $database->quote('%' . $term . '%');
        $quotedPrefix = $database->quote($term . '%');

        $sql = "
            SELECT OXID
            FROM {$categoryView}
            WHERE OXACTIVE = 1
                AND OXHIDDEN = 0
                AND OXTITLE LIKE {$quotedContains}
            ORDER BY (OXTITLE LIKE {$quotedPrefix}) DESC, CHAR_LENGTH(OXTITLE) ASC
            LIMIT {$limit}
        ";

        return (array) $database->getCol($sql);
    }

    public function isAvailable(int $shopId, int $langId): bool
    {
        $sql = '
            SELECT 1
            FROM ' . $this->tables->index($shopId) . '
            WHERE FOUN10LANGID = :langId
            LIMIT 1';

        try {
            return (bool) DatabaseProvider::getDb()->getOne($sql, [':langId' => $langId]);
        } catch (\Throwable $exception) {
            // No table for this shop, which is what a shop that has never been
            // indexed looks like - the caller falls back to the stock search.
            return false;
        }
    }
}
