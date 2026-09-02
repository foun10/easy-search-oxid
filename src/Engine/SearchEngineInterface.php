<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine;

use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Query\SuggestQuery;
use foun10\EasySearch\Engine\Result\SearchResult;
use foun10\EasySearch\Engine\Result\SuggestResult;

/**
 * Contract between the shop and a search implementation.
 *
 * Everything above this interface (controllers, templates, facet UI) only
 * knows SearchQuery/SearchResult. That keeps the door open to swapping the
 * MySql engine for an external one later without touching the frontend.
 *
 * Implementations return IDs and metadata only, never loaded article objects.
 * Loading stays the job of ArticleList so the shop's pricing and permission
 * logic keeps applying unchanged.
 */
interface SearchEngineInterface
{
    /**
     * Runs a search including facet calculation.
     *
     * Typo tolerance happens inside the implementation: if the original term
     * yields no more than FOUN10EASYSEARCH_CORRECTION_MAX_HITS hits and correction
     * is allowed, the engine searches again with the corrected term and
     * records that in SearchResult::getCorrection().
     */
    public function search(SearchQuery $query): SearchResult;

    /**
     * Returns suggestions for the suggest box (terms plus product, category
     * and brand IDs). Must answer noticeably faster than search() and may be
     * less precise in exchange.
     *
     * Returns IDs for the same reason search() does - rendering data belongs
     * to the shop, not to the index, or it goes stale between rebuilds.
     */
    public function suggest(SuggestQuery $query): SuggestResult;

    /**
     * Is the engine ready to serve (index present and not empty)?
     *
     * Evaluated while rendering so an empty or broken index can fall back to
     * the shop's stock search instead of showing the customer an empty result
     * list.
     */
    public function isAvailable(int $shopId, int $langId): bool;
}
