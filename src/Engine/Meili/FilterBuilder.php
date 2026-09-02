<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Meili;

use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Meili\IndexSchema;

/**
 * Translates a SearchQuery into Meilisearch filter expressions.
 *
 * The counterpart of the MySql ConditionBuilder, and a far smaller one: there
 * is no fulltext expression to assemble here, because the term goes to
 * Meilisearch as the term. What is left is the narrowing - visibility,
 * category, manufacturer, price, facets.
 *
 * Filters are returned as a list, which Meilisearch AND-combines. Several
 * values within one facet are OR-combined through IN, the same rule the SQL
 * side applies: colour red OR blue, but colour AND size.
 *
 * One difference to the SQL engine is worth knowing. There, a facet is checked
 * per variant, so a size 38 blouse cannot appear under "size 42" because a
 * sibling variant matches. Here it holds for the same reason and without any
 * extra work: a document *is* a variant, so a filter on it is already a filter
 * on the variant.
 */
class FilterBuilder
{
    /**
     * @param string|null $excludeAttributeId Facet whose own filter is left
     *                                        out, for its own hit counts
     *
     * @return string[]
     */
    public function build(SearchQuery $query, ?string $excludeAttributeId = null): array
    {
        // visible already carries active plus the shop's stock rule, decided
        // once at index time - see VisibilityResolver.
        $filters = ['visible = true'];

        if ($query->getCategoryId() !== null) {
            $filters[] = 'categoryIds = ' . $this->quote($query->getCategoryId());
        }

        if ($query->getManufacturerId() !== null) {
            $filters[] = 'manufacturerId = ' . $this->quote($query->getManufacturerId());
        }

        if ($query->getPriceFrom() !== null) {
            $filters[] = 'price >= ' . $this->number($query->getPriceFrom());
        }

        if ($query->getPriceTo() !== null) {
            $filters[] = 'price <= ' . $this->number($query->getPriceTo());
        }

        foreach ($query->getFilters() as $filter) {
            if ($filter->isEmpty() || $filter->getAttributeId() === $excludeAttributeId) {
                continue;
            }

            $quoted = array_map(
                fn (string $valueId): string => $this->quote($valueId),
                $filter->getValueIds()
            );

            $filters[] = IndexSchema::filterField($filter->getAttributeId())
                . ' IN [' . implode(', ', $quoted) . ']';
        }

        return $filters;
    }

    /**
     * Meilisearch reads a filter as an expression, so a value carrying a quote
     * or a backslash would end it early.
     */
    public function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Locale independent: a comma decimal separator would turn one number into
     * two operands.
     */
    protected function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }
}
