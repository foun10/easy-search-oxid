<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Result;

/**
 * Result of a search.
 *
 * Deliberately carries IDs only: articles are loaded afterwards through
 * ArticleList so that the shop's pricing, discount, scale price and
 * visibility rules keep applying unchanged.
 */
class SearchResult
{
    /**
     * @param string[] $productIds Parent article IDs in hit order
     * @param Facet[]  $facets
     */
    public function __construct(
        protected readonly array $productIds,
        protected readonly int $totalCount,
        protected readonly array $facets = [],
        protected readonly ?Correction $correction = null,
        protected readonly float $duration = 0.0
    ) {
    }

    public static function empty(?Correction $correction = null): static
    {
        return new static([], 0, [], $correction);
    }

    /**
     * @return string[]
     */
    public function getProductIds(): array
    {
        return $this->productIds;
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function isEmpty(): bool
    {
        return $this->totalCount === 0;
    }

    /**
     * @return Facet[]
     */
    public function getFacets(): array
    {
        return $this->facets;
    }

    public function getFacet(string $attributeId): ?Facet
    {
        foreach ($this->facets as $facet) {
            if ($facet->getAttributeId() === $attributeId) {
                return $facet;
            }
        }

        return null;
    }

    public function hasActiveFilters(): bool
    {
        foreach ($this->facets as $facet) {
            if ($facet->hasSelection()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set when a corrected term was searched instead of the one entered, or
     * when a suggestion should be offered to the customer.
     */
    public function getCorrection(): ?Correction
    {
        return $this->correction;
    }

    /**
     * Runtime in seconds, for monitoring and the query log.
     */
    public function getDuration(): float
    {
        return $this->duration;
    }
}
