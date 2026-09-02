<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

/**
 * Splits the composite value of the colour hexcode attribute.
 *
 * The attribute does not hold a hex code despite its name. Values look like
 *
 *     Schwarz_#272727
 *
 * The shape is a convention of the source data rather than of this module: the
 * name becomes the label and the hex becomes the swatch.
 *
 * Treating the raw value as a colour produces `background: Schwarz_#272727`,
 * which browsers drop silently - a blank tile rather than an error. Hence one
 * parser used everywhere the value is displayed.
 *
 * Anything that does not match keeps the whole value as its name and reports no
 * hex, so unexpected data degrades to a plain text label instead of vanishing.
 */
class ColorValue
{
    /**
     * Greedy name so colour names containing an underscore still work; only the
     * trailing _#hex is taken as the code.
     */
    protected const PATTERN = '/^(.*)_(#[0-9a-fA-F]{3,8})$/';

    public function __construct(
        protected readonly string $name,
        protected readonly ?string $hex
    ) {
    }

    public static function parse(string $value): self
    {
        $value = trim($value);

        if (preg_match(self::PATTERN, $value, $matches) === 1) {
            return new self(trim($matches[1]), $matches[2]);
        }

        return new self($value, null);
    }

    /**
     * Readable colour name, e.g. "Schwarz".
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Hex code including the leading hash, or null when the value did not
     * carry one.
     */
    public function getHex(): ?string
    {
        return $this->hex;
    }

    public function hasHex(): bool
    {
        return $this->hex !== null;
    }
}
