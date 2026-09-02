<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine;

use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Query\SuggestQuery;
use foun10\EasySearch\Engine\Result\SearchResult;
use foun10\EasySearch\Engine\Result\SuggestResult;

/**
 * The engine a shop gets when FOUN10EASYSEARCH_ENGINE says "null".
 *
 * Reports itself unavailable, which is the one answer every caller already
 * handles: the search page, the category listing and the manufacturer listing
 * all fall back to the shop's own logic when the engine is not available. So
 * switching a single subshop back to stock OXID search is a setting, not a
 * deployment.
 */
class NullSearchEngine implements SearchEngineInterface
{
    public function search(SearchQuery $query): SearchResult
    {
        return SearchResult::empty();
    }

    public function suggest(SuggestQuery $query): SuggestResult
    {
        return SuggestResult::empty();
    }

    public function isAvailable(int $shopId, int $langId): bool
    {
        return false;
    }
}
