<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Result;

/**
 * A filter group on the result page, e.g. colour, size or material.
 *
 * Which facets appear and in what order is configured per subshop on the
 * Attributes screen. That arrangement is stored in foun10easysearchattribute
 * rather than in a module setting, so a deployment cannot overwrite it - see
 * AttributeConfiguration.
 */
class Facet
{
    public const TYPE_LIST = 'list';
    public const TYPE_COLOR = 'color';
    public const TYPE_RANGE = 'range';

    /**
     * @param FacetValue[] $values
     */
    public function __construct(
        protected readonly string $attributeId,
        protected readonly string $title,
        protected readonly array $values,
        protected readonly string $type = self::TYPE_LIST,
        protected readonly int $position = 0
    ) {
    }

    public function getAttributeId(): string
    {
        return $this->attributeId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return FacetValue[]
     */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function hasSelection(): bool
    {
        foreach ($this->values as $value) {
            if ($value->isSelected()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return FacetValue[]
     */
    public function getSelectedValues(): array
    {
        return array_values(array_filter(
            $this->values,
            static fn (FacetValue $value): bool => $value->isSelected()
        ));
    }

    /**
     * Facets with nothing left to select are hidden by the template.
     */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
