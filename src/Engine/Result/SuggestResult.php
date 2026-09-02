<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Result;

/**
 * Payload of the suggest endpoint.
 *
 * Like SearchResult, this carries IDs rather than rendered product data. The
 * controller loads the articles and builds the JSON, so titles, pictures,
 * links and prices always come from the shop and can never go stale between
 * two reindexes.
 *
 * Everything here is a plain scalar array - the controller resolves the IDs
 * into article data and serialises the result itself.
 */
class SuggestResult
{
    /**
     * @param string[] $terms      Completed or corrected search terms
     * @param string[] $productIds Parent article IDs
     * @param string[] $categoryIds
     * @param string[] $brandIds
     */
    public function __construct(
        protected readonly array $terms = [],
        protected readonly array $productIds = [],
        protected readonly array $categoryIds = [],
        protected readonly array $brandIds = [],
        protected readonly int $totalCount = 0
    ) {
    }

    public static function empty(): static
    {
        return new static();
    }

    /**
     * @return string[]
     */
    public function getTerms(): array
    {
        return $this->terms;
    }

    /**
     * @return string[]
     */
    public function getProductIds(): array
    {
        return $this->productIds;
    }

    /**
     * @return string[]
     */
    public function getCategoryIds(): array
    {
        return $this->categoryIds;
    }

    /**
     * @return string[]
     */
    public function getBrandIds(): array
    {
        return $this->brandIds;
    }

    /**
     * Hits the full search would return for this term. Drives the
     * "show all N results" link at the foot of the dropdown.
     */
    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function isEmpty(): bool
    {
        return $this->terms === []
            && $this->productIds === []
            && $this->categoryIds === []
            && $this->brandIds === [];
    }
}
