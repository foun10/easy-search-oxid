<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Query;

/**
 * A selection within one facet.
 *
 * Several values of the same facet are OR-combined (colour red OR blue),
 * different facets are AND-combined (colour red AND size 40). That matches
 * what customers know from other fashion shops.
 */
class FacetFilter
{
    /**
     * @param string[] $valueIds
     */
    public function __construct(
        protected readonly string $attributeId,
        protected readonly array $valueIds
    ) {
    }

    public function getAttributeId(): string
    {
        return $this->attributeId;
    }

    /**
     * @return string[]
     */
    public function getValueIds(): array
    {
        return $this->valueIds;
    }

    public function isEmpty(): bool
    {
        return $this->valueIds === [];
    }
}
