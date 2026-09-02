<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index;

/**
 * One indexable variant, in a form no search backend is aware of.
 *
 * This is the seam that keeps a later Meilisearch move cheap: reading the
 * catalogue is by far the most expensive and most shop-specific part of
 * indexing, and it produces these objects. A Meilisearch writer consumes
 * toArray() and posts it as a JSON document; the MySql writer maps the same
 * fields onto columns. Neither influences how documents are built.
 *
 * Granularity is one row per variant, grouped back to the parent through
 * getGroupId(). Faceting on size or colour is only correct at variant level.
 */
class IndexDocument
{
    /**
     * @param string[] $categoryPaths Full paths, e.g. "Damen > Waesche > BHs"
     * @param array<int, array{attributeId: string, title: string, valueId: string, value: string, hex: string|null}> $attributes
     * @param string[] $categoryIds
     */
    public function __construct(
        protected readonly string $id,
        protected readonly int $shopId,
        protected readonly int $langId,
        protected readonly string $articleId,
        protected readonly string $parentId,
        protected readonly string $groupId,
        protected readonly string $title,
        protected readonly string $artNum,
        protected readonly string $ean,
        protected readonly string $mpn,
        protected readonly string $brand,
        protected readonly string $manufacturerId,
        protected readonly array $categoryPaths,
        protected readonly array $attributes,
        protected readonly string $searchText,
        protected readonly string $boostText,
        protected readonly float $price,
        protected readonly float $stock,
        protected readonly int $soldAmount,
        protected readonly ?string $insertDate,
        protected readonly bool $visible,
        protected readonly array $categoryIds = []
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function getLangId(): int
    {
        return $this->langId;
    }

    public function getArticleId(): string
    {
        return $this->articleId;
    }

    public function getParentId(): string
    {
        return $this->parentId;
    }

    /**
     * Parent article for variants, own ID for standalone articles. Results are
     * collapsed on this value so a customer sees one product, not twelve
     * sizes of it.
     */
    public function getGroupId(): string
    {
        return $this->groupId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getArtNum(): string
    {
        return $this->artNum;
    }

    public function getEan(): string
    {
        return $this->ean;
    }

    public function getMpn(): string
    {
        return $this->mpn;
    }

    /**
     * The manufacturer this article belongs to, so a manufacturer listing
     * can be answered by the engine. The brand beside it is the title and
     * feeds the searchable text; only this is stable enough to filter on.
     */
    public function getManufacturerId(): string
    {
        return $this->manufacturerId;
    }

    public function getBrand(): string
    {
        return $this->brand;
    }

    /**
     * @return string[]
     */
    public function getCategoryPaths(): array
    {
        return $this->categoryPaths;
    }

    /**
     * The categories this product sits in, as IDs.
     *
     * Carried alongside the readable paths because a document store filters a
     * category listing on the document itself, where the MySql writer derives
     * a link table instead. Refreshing them stays its own operation in both
     * connectors - see IndexWriterInterface::rebuildCategories() - this is what
     * a full rebuild starts from, not what keeps them current.
     *
     * @return string[]
     */
    public function getCategoryIds(): array
    {
        return $this->categoryIds;
    }

    /**
     * @return array<int, array{attributeId: string, title: string, valueId: string, value: string, hex: string|null}>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Normalised blob of everything searchable, including the long
     * description. Feeds the low-weight fulltext index.
     */
    public function getSearchText(): string
    {
        return $this->searchText;
    }

    /**
     * Normalised title, brand and identifiers only. Feeds the high-weight
     * fulltext index so a title hit outranks a mention buried in a
     * description.
     */
    public function getBoostText(): string
    {
        return $this->boostText;
    }

    /**
     * Effective price after article discounts, as an anonymous visitor would
     * see it. User group discounts cannot be resolved at index time and are
     * not included.
     *
     * Only ever used to sort and to filter by range - the price shown to the
     * customer still comes from the shop's own price logic.
     */
    public function getPrice(): float
    {
        return $this->price;
    }

    public function getStock(): float
    {
        return $this->stock;
    }

    public function getSoldAmount(): int
    {
        return $this->soldAmount;
    }

    public function getInsertDate(): ?string
    {
        return $this->insertDate;
    }

    /**
     * The single gate a result list filters on. Decided by
     * VisibilityResolver at index time, not reassembled per query.
     */
    public function isVisible(): bool
    {
        return $this->visible;
    }

    /**
     * Backend-neutral representation. Meilisearch can take this as-is once
     * the attribute list is flattened into filterable fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shopId' => $this->shopId,
            'langId' => $this->langId,
            'articleId' => $this->articleId,
            'parentId' => $this->parentId,
            'groupId' => $this->groupId,
            'title' => $this->title,
            'artNum' => $this->artNum,
            'ean' => $this->ean,
            'mpn' => $this->mpn,
            'brand' => $this->brand,
            'manufacturerId' => $this->manufacturerId,
            'categoryPaths' => $this->categoryPaths,
            'categoryIds' => $this->categoryIds,
            'attributes' => $this->attributes,
            'searchText' => $this->searchText,
            'boostText' => $this->boostText,
            'price' => $this->price,
            'stock' => $this->stock,
            'soldAmount' => $this->soldAmount,
            'insertDate' => $this->insertDate,
            'visible' => $this->visible,
        ];
    }
}
