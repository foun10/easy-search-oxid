<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine;

use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Query\SuggestQuery;
use foun10\EasySearch\Engine\Result\SearchResult;
use foun10\EasySearch\Engine\Result\SuggestResult;

/**
 * The engine everything above this module talks to.
 *
 * SearchEngineInterface is aliased to this class rather than to one
 * implementation, so which backend answers is decided per request from the
 * FOUN10EASYSEARCH_ENGINE setting of the active subshop. Callers - the search
 * controller, the two listing controllers, the suggest endpoint - keep asking
 * for the interface and never learn the difference.
 *
 * Resolved once per instance: the setting is read per shop, and one request
 * serves one shop.
 */
class ConfiguredSearchEngine implements SearchEngineInterface
{
    protected ?SearchEngineInterface $engine = null;

    public function __construct(
        protected EngineLocator $engineLocator
    ) {
    }

    public function search(SearchQuery $query): SearchResult
    {
        return $this->resolve()->search($query);
    }

    public function suggest(SuggestQuery $query): SuggestResult
    {
        return $this->resolve()->suggest($query);
    }

    public function isAvailable(int $shopId, int $langId): bool
    {
        return $this->resolve()->isAvailable($shopId, $langId);
    }

    protected function resolve(): SearchEngineInterface
    {
        return $this->engine ??= $this->engineLocator->getConfigured();
    }
}
