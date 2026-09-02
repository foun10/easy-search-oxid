<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use foun10\EasySearch\Engine\Query\FacetFilter;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Engine\Result\FacetValue;

/**
 * Turns counted facet values into the sidebar the templates render.
 *
 * Shared by both connectors on purpose. Counting is the part that differs -
 * one grouped SQL query here, a facet distribution over there - but which
 * facets appear, what they are called, how a colour value is split, how many
 * values are shown and when a facet is worth showing at all is shop behaviour,
 * not storage behaviour. Keeping it in one place is also what makes a
 * comparison between the two engines meaningful: any difference in the sidebar
 * is then a difference in the counts, not in the assembly.
 *
 * The caller delivers values already ordered. Re-sorting them here would
 * quietly change ties, because PHP compares bytes where the database compares
 * by collation - "Öl" and "Oel" land differently.
 */
class FacetAssembler
{
    /**
     * Values a facet needs before it is worth showing.
     *
     * A group offering one choice cannot narrow anything the customer is not
     * already looking at, so it is a control that does nothing - and one that
     * costs a click to find that out. Filtered here rather than hidden in the
     * template, so hasFoun10Facets(), the button badge and the panel all agree
     * on what exists.
     */
    protected const MIN_VALUES = 2;

    public function __construct(
        protected ModuleSettings $moduleSettings,
        protected AttributeConfiguration $attributeConfiguration,
        protected AttributeTitles $attributeTitles
    ) {
    }

    /**
     * @param string[]                                                                  $attributeIds
     * @param array<string, array<int, array{valueId: string, value: string, count: int}>> $counts     Ordered values per attribute
     * @param callable(string, string, string): string                                  $labelResolver Label of a selected value that dropped out of the result set, by attribute ID, value ID and display mode
     *
     * @return Facet[]
     */
    public function assemble(
        SearchQuery $query,
        array $attributeIds,
        array $counts,
        callable $labelResolver
    ): array {
        if ($attributeIds === []) {
            return [];
        }

        $titles = $this->attributeTitles->get($attributeIds, $query->getShopId(), $query->getLangId());

        // A merchant's own label wins over the attribute's title: the ERP names
        // these things "Farbcode_HEX", which is not a word to put in front of a
        // customer.
        $customTitles = $this->attributeConfiguration->getCustomTitles(
            $query->getShopId(),
            $query->getLangId()
        );

        $displayModes = $this->attributeConfiguration->getDisplayModes($query->getShopId());
        $selection = $this->getSelectionMap($query);
        $facets = [];
        $position = 0;

        foreach ($attributeIds as $attributeId) {
            $attributeId = (string) $attributeId;
            $mode = $displayModes[$attributeId] ?? FacetDisplay::MODE_DEFAULT;
            $values = $this->toValues(
                $counts[$attributeId] ?? [],
                $selection[$attributeId] ?? [],
                $mode,
                fn (string $valueId): string => $labelResolver($attributeId, $valueId, $mode)
            );

            if (!$this->isWorthShowing($values)) {
                continue;
            }

            $facets[] = new Facet(
                $attributeId,
                $customTitles[$attributeId] ?? $titles[$attributeId] ?? $attributeId,
                $values,
                FacetDisplay::toFacetType($mode),
                $position++
            );
        }

        return $facets;
    }

    /**
     * @return array<string, string[]> Selected value IDs keyed by attribute ID
     */
    public function getSelectionMap(SearchQuery $query): array
    {
        $selection = [];

        foreach ($query->getFilters() as $filter) {
            if (!$filter instanceof FacetFilter) {
                continue;
            }

            $selection[$filter->getAttributeId()] = $filter->getValueIds();
        }

        return $selection;
    }

    /**
     * @param array<int, array{valueId: string, value: string, count: int}> $counts
     * @param string[]                                                     $selectedValueIds
     * @param callable(string): string                                     $labelResolver
     *
     * @return FacetValue[]
     */
    protected function toValues(array $counts, array $selectedValueIds, string $mode, callable $labelResolver): array
    {
        $counts = array_slice($counts, 0, $this->moduleSettings->getFacetValueLimit());
        $isColor = FacetDisplay::isColor($mode);
        $values = [];
        $seen = [];

        foreach ($counts as $entry) {
            $valueId = (string) $entry['valueId'];
            $seen[] = $valueId;

            // Only a facet configured as a colour splits its values: the ERP
            // writes them as "Name_#hexcode", and the customer must see the
            // name while the swatch gets the code. Every other attribute keeps
            // its value verbatim - guessing by shape used to turn any value
            // that happened to end in _#something into a colour tile.
            $rawValue = (string) $entry['value'];
            $color = $isColor ? ColorValue::parse($rawValue) : null;

            $values[] = new FacetValue(
                $valueId,
                $color !== null ? $color->getName() : trim($rawValue),
                (int) $entry['count'],
                in_array($valueId, $selectedValueIds, true),
                $color !== null ? $color->getHex() : null
            );
        }

        // A selected value that dropped out of the result set still has to be
        // rendered, otherwise the customer cannot switch it off again.
        foreach ($selectedValueIds as $selectedValueId) {
            if (in_array($selectedValueId, $seen, true)) {
                continue;
            }

            $values[] = new FacetValue($selectedValueId, $labelResolver($selectedValueId), 0, true);
        }

        return $values;
    }

    /**
     * Whether a facet earns its place in the sidebar.
     *
     * Counted on the values that are actually reachable, because the ones that
     * lead nowhere are not rendered any more. A facet whose values have all
     * been ruled out by another selection would otherwise be a headline with
     * nothing under it.
     *
     * A single value is dropped, with one exception: if that value is the one
     * the customer selected, the facet has to stay. Hiding it would leave an
     * active filter with no way to switch it off - the chips above the list
     * would be the only way back, and on a filter combination that matches
     * nothing even those are easy to miss.
     *
     * @param FacetValue[] $values
     */
    protected function isWorthShowing(array $values): bool
    {
        $reachable = 0;

        foreach ($values as $value) {
            // A selected value keeps its facet on screen whatever else happens
            // to it - see above.
            if ($value->isSelected()) {
                return true;
            }

            if ($value->isSelectable()) {
                $reachable++;
            }
        }

        return $reachable >= self::MIN_VALUES;
    }
}
