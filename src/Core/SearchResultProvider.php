<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use foun10\EasySearch\Engine\Result\SearchResult;

/**
 * Request-scoped hand-off between the Search model and the controller.
 *
 * OXID's SearchController calls oxNew(Search::class) inside init() and throws
 * the instance away, so there is no object both sides share. The model
 * extension parks the engine result here and the controller picks it up for
 * facets and the spelling correction, without either having to run the query
 * twice.
 *
 * Lives for one request only - never cache it between requests, the result is
 * specific to one query, shop, language and filter combination.
 */
class SearchResultProvider
{
    protected ?SearchResult $searchResult = null;

    public function set(SearchResult $searchResult): void
    {
        $this->searchResult = $searchResult;
    }

    public function get(): ?SearchResult
    {
        return $this->searchResult;
    }

    public function has(): bool
    {
        return $this->searchResult !== null;
    }
}
