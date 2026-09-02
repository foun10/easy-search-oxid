<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Query;

/**
 * Request from the suggest box. Deliberately narrow: the endpoint is called on
 * every (debounced) keystroke, so it asks for as little as it can get away
 * with.
 */
class SuggestQuery
{
    public function __construct(
        protected readonly string $term,
        protected readonly int $shopId,
        protected readonly int $langId,
        protected readonly int $termLimit = 6,
        protected readonly int $productLimit = 6,
        protected readonly int $categoryLimit = 3,
        protected readonly int $brandLimit = 3
    ) {
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function getLangId(): int
    {
        return $this->langId;
    }

    public function getTermLimit(): int
    {
        return $this->termLimit;
    }

    public function getProductLimit(): int
    {
        return $this->productLimit;
    }

    public function getCategoryLimit(): int
    {
        return $this->categoryLimit;
    }

    public function getBrandLimit(): int
    {
        return $this->brandLimit;
    }
}
