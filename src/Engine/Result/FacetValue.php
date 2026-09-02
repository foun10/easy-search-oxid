<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Result;

/**
 * A single selectable value of a facet, including its hit count.
 *
 * The count is calculated without the facet's own selection applied -
 * otherwise every other colour would show zero once "red" is clicked.
 */
class FacetValue
{
    public function __construct(
        protected readonly string $valueId,
        protected readonly string $label,
        protected readonly int $count,
        protected readonly bool $selected = false,
        protected readonly ?string $hexCode = null
    ) {
    }

    public function getValueId(): string
    {
        return $this->valueId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function isSelected(): bool
    {
        return $this->selected;
    }

    /**
     * Only filled for colour facets, parsed out of the "Name_#hexcode" value by
     * ColorValue.
     */
    public function getHexCode(): ?string
    {
        return $this->hexCode;
    }

    /**
     * Values without hits stay visible but not clickable as long as they are
     * selected, otherwise the active filter would vanish from the UI.
     */
    public function isSelectable(): bool
    {
        return $this->count > 0 || $this->selected;
    }
}
