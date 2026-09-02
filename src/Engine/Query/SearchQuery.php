<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Query;

/**
 * Immutable description of a search request.
 *
 * Filled both by the search page and by the category page: on the category
 * page the term stays empty and the result is narrowed by categoryId plus
 * filters only. That way search and navigation share one facet calculation.
 */
class SearchQuery
{
    public const SORT_RELEVANCE = 'relevance';
    public const SORT_PRICE_ASC = 'price_asc';
    public const SORT_PRICE_DESC = 'price_desc';
    public const SORT_TITLE_ASC = 'title_asc';
    public const SORT_NEWEST = 'newest';
    public const SORT_BESTSELLER = 'bestseller';

    public const SORT_OPTIONS = [
        self::SORT_RELEVANCE,
        self::SORT_PRICE_ASC,
        self::SORT_PRICE_DESC,
        self::SORT_TITLE_ASC,
        self::SORT_NEWEST,
        self::SORT_BESTSELLER,
    ];

    /**
     * @param FacetFilter[] $filters
     */
    public function __construct(
        protected readonly string $term,
        protected readonly int $shopId,
        protected readonly int $langId,
        protected readonly array $filters = [],
        protected readonly string $sort = self::SORT_RELEVANCE,
        protected readonly int $offset = 0,
        protected readonly int $limit = 24,
        protected readonly ?string $categoryId = null,
        protected readonly ?string $manufacturerId = null,
        protected readonly ?float $priceFrom = null,
        protected readonly ?float $priceTo = null,
        protected readonly bool $allowCorrection = true
    ) {
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    public function hasTerm(): bool
    {
        return trim($this->term) !== '';
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function getLangId(): int
    {
        return $this->langId;
    }

    /**
     * @return FacetFilter[]
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getSort(): string
    {
        return $this->sort;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getCategoryId(): ?string
    {
        return $this->categoryId;
    }

    public function getManufacturerId(): ?string
    {
        return $this->manufacturerId;
    }

    public function getPriceFrom(): ?float
    {
        return $this->priceFrom;
    }

    public function getPriceTo(): ?float
    {
        return $this->priceTo;
    }

    public function isCorrectionAllowed(): bool
    {
        return $this->allowCorrection;
    }

    /**
     * Builds the query for the second pass with a corrected term. Correction
     * is switched off on the copy so no correction loop can occur.
     */
    public function withCorrectedTerm(string $term): static
    {
        return new static(
            $term,
            $this->shopId,
            $this->langId,
            $this->filters,
            $this->sort,
            $this->offset,
            $this->limit,
            $this->categoryId,
            $this->manufacturerId,
            $this->priceFrom,
            $this->priceTo,
            false
        );
    }

    /**
     * Copy that asks for a different number of hits.
     *
     * For the facet endpoint, which wants the facets and the total but none of
     * the products - the offcanvas is deciding what to select, the list behind
     * it does not change until the customer applies. Zero is not passed to an
     * engine (Meilisearch refuses a page size of 0), so the caller asks for one
     * and ignores it.
     */
    public function withLimit(int $limit): static
    {
        return new static(
            $this->term,
            $this->shopId,
            $this->langId,
            $this->filters,
            $this->sort,
            $this->offset,
            max(1, $limit),
            $this->categoryId,
            $this->manufacturerId,
            $this->priceFrom,
            $this->priceTo,
            $this->allowCorrection
        );
    }

    /**
     * Copy without one facet filter. Needed to calculate a facet's hit counts
     * without its own selection applied.
     */
    public function withoutFilter(string $attributeId): static
    {
        $filters = array_values(array_filter(
            $this->filters,
            static fn (FacetFilter $filter): bool => $filter->getAttributeId() !== $attributeId
        ));

        return new static(
            $this->term,
            $this->shopId,
            $this->langId,
            $filters,
            $this->sort,
            $this->offset,
            $this->limit,
            $this->categoryId,
            $this->manufacturerId,
            $this->priceFrom,
            $this->priceTo,
            $this->allowCorrection
        );
    }
}
