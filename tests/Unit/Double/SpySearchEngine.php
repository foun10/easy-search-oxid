<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Query\SuggestQuery;
use foun10\EasySearch\Engine\Result\SearchResult;
use foun10\EasySearch\Engine\Result\SuggestResult;
use foun10\EasySearch\Engine\SearchEngineInterface;
use RuntimeException;

/**
 * A search engine that answers what the test tells it to, and counts.
 *
 * The benchmark is about how often each engine is asked and what it answered,
 * so both are recorded here. `$failing` stands in for an engine that is
 * reachable but broken - the benchmark has to survive it rather than abort
 * halfway through a measurement.
 */
class SpySearchEngine implements SearchEngineInterface
{
    /** @var SearchQuery[] */
    public array $searches = [];

    public bool $available = true;

    public bool $failing = false;

    /**
     * Makes a search take measurable time. The benchmark divides by what it
     * measured, and an engine that answers in zero time cannot be divided by.
     */
    public int $delayMicroseconds = 0;

    /**
     * @param string[] $productIds
     */
    public function __construct(
        public array $productIds = [],
        public int $totalCount = 0,
        public array $facets = []
    ) {
    }

    public function search(SearchQuery $query): SearchResult
    {
        $this->searches[] = $query;

        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        if ($this->failing) {
            throw new RuntimeException('the engine is not answering');
        }

        return new SearchResult($this->productIds, $this->totalCount, $this->facets);
    }

    /** @var SuggestQuery[] */
    public array $suggests = [];

    /** What suggest() answers; the empty result unless a test says otherwise */
    public ?SuggestResult $suggestResult = null;

    public function suggest(SuggestQuery $query): SuggestResult
    {
        $this->suggests[] = $query;

        if ($this->failing) {
            throw new RuntimeException('the engine is not answering');
        }

        return $this->suggestResult ?? SuggestResult::empty();
    }

    public function isAvailable(int $shopId, int $langId): bool
    {
        return $this->available;
    }

    public function searchCount(): int
    {
        return count($this->searches);
    }
}
