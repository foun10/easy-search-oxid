<?php
declare(strict_types=1);

namespace foun10\EasySearch\Log;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Correction\Normalizer;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Result\SearchResult;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use Throwable;

/**
 * Counts what customers searched for and what it found them.
 *
 * Two questions, one table: which terms are used most, and which ones return
 * nothing. The second is the one worth acting on - a search with no results is
 * either a word missing from the vocabulary, which a synonym rule fixes, or a
 * product that is not there.
 *
 * Counted per term and day rather than logged per search. That keeps the table
 * to the size of the vocabulary instead of the traffic, and it stores nothing
 * about who searched: no session, no address, only the words.
 *
 * **One search is the question, not every view of the answer.** Applying a
 * filter, turning a page or re-sorting all come back through here with the same
 * term, and counting them would report a customer who filtered twice as three
 * people looking for the word. Worse, it would put every dead filter
 * combination into the zero hit list, which is the list that is supposed to be
 * worth working through. So only the plain, unfiltered first page counts - see
 * isFirstLook(), which reads that off the query rather than out of a session.
 *
 * A reload is counted, and there is no cheap way around that: telling one apart
 * from a fresh search needs state per visitor, which is exactly what this table
 * was built to avoid.
 *
 * Input that is not a search term at all - injection payloads, whole URLs,
 * traversal paths - never reaches the table: TermFilter sorts it out first.
 *
 * **Called once per search page**, from the Search model extension. Not from
 * the engine: the facet endpoint runs the same query again on every click of
 * the filter panel and the suggest box fires on every keystroke, so logging
 * where the searching happens would count one customer's search a dozen times.
 * A second call within the same request is ignored for the same reason.
 */
class SearchLogger
{
    /**
     * Longer input is not a search term. The column is sized to match, and
     * cutting it here keeps a pathological query out of the database.
     */
    protected const MAX_TERM_LENGTH = 255;

    /**
     * Terms already counted in this request, so a controller asking for the
     * article list twice does not count the search twice.
     *
     * @var array<string, true>
     */
    protected array $counted = [];

    public function __construct(
        protected Normalizer $normalizer,
        protected ModuleSettings $moduleSettings,
        protected TermFilter $termFilter
    ) {
    }

    /**
     * Records one search. Never throws: a failure to count must not cost the
     * customer their results.
     */
    public function log(SearchQuery $query, SearchResult $result): void
    {
        try {
            $this->count($query, $result);
        } catch (Throwable $exception) {
            Registry::getLogger()->error(
                'foun10EasySearch: could not log the search - ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }

    /**
     * Whether this request is somebody searching, rather than working with a
     * result they already have.
     *
     * All four signals come off the query, so nothing has to be remembered
     * between requests:
     *
     *  - a facet filter or a price range means the customer is narrowing a
     *    result list they are already looking at;
     *  - an offset means page two and later;
     *  - a sort other than relevance means they reordered what they found.
     *
     * The search box itself always produces the opposite: no filters, no
     * offset, relevance.
     */
    protected function isFirstLook(SearchQuery $query): bool
    {
        foreach ($query->getFilters() as $filter) {
            if (!$filter->isEmpty()) {
                return false;
            }
        }

        // The sort is read off the request as well as off the query: OXID
        // carries the search page's sorting through the session, so a customer
        // who reordered once has it applied to the next search too - and that
        // next search is a real one. The parameter, on the other hand, appears
        // exactly when they click a sort control.
        if (Registry::getRequest()->getRequestEscapedParameter('listorderby') !== null) {
            return false;
        }

        return $query->getPriceFrom() === null
            && $query->getPriceTo() === null
            && $query->getOffset() === 0
            && $query->getSort() === SearchQuery::SORT_RELEVANCE;
    }

    protected function count(SearchQuery $query, SearchResult $result): void
    {
        if (!$this->moduleSettings->isSearchLogEnabled()) {
            return;
        }

        $raw = trim($query->getTerm());

        if ($raw === '') {
            // A category or manufacturer listing is not a search.
            return;
        }

        if (!$this->isFirstLook($query)) {
            return;
        }

        if ($this->termFilter->isSuspicious($raw)) {
            // A scanner's payload is not a customer telling you what they
            // wanted, and the report it would land in is read by people. Not
            // stored at all rather than hidden later - see TermFilter.
            return;
        }

        $raw = mb_substr($raw, 0, self::MAX_TERM_LENGTH);
        $term = $this->normalizer->normalize($raw);

        if ($term === '') {
            return;
        }

        $key = $query->getShopId() . '_' . $query->getLangId() . '_' . $term;

        if (isset($this->counted[$key])) {
            return;
        }

        $this->counted[$key] = true;

        $correction = $result->getCorrection();
        $day = date('Y-m-d');

        DatabaseProvider::getDb()->execute(
            'INSERT INTO ' . SearchLog::TABLE . ' (
                OXID, OXSHOPID, FOUN10LANGID, FOUN10DAY, FOUN10TERM, FOUN10TERMRAW,
                FOUN10EASYSEARCHES, FOUN10HITS, FOUN10CORRECTED, FOUN10LASTSEEN
             ) VALUES (
                :id, :shopId, :langId, :day, :term, :raw, 1, :hits, :corrected, NOW()
             )
             ON DUPLICATE KEY UPDATE
                FOUN10EASYSEARCHES = FOUN10EASYSEARCHES + 1,
                FOUN10HITS = VALUES(FOUN10HITS),
                FOUN10TERMRAW = VALUES(FOUN10TERMRAW),
                FOUN10CORRECTED = VALUES(FOUN10CORRECTED),
                FOUN10LASTSEEN = VALUES(FOUN10LASTSEEN)',
            [
                ':id' => md5($query->getShopId() . '_' . $query->getLangId() . '_' . $day . '_' . $term),
                ':shopId' => $query->getShopId(),
                ':langId' => $query->getLangId(),
                ':day' => $day,
                ':term' => $term,
                ':raw' => $raw,
                // The hit count of the most recent search for this term, not a
                // sum: it answers "does this find anything today", and adding
                // it up across searches would answer nothing at all.
                ':hits' => $result->getTotalCount(),
                ':corrected' => $correction !== null ? $correction->getCorrected() : '',
            ]
        );
    }
}
