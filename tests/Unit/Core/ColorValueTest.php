<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Core;

use foun10\EasySearch\Core\ColorValue;
use PHPUnit\Framework\TestCase;

/**
 * Splits "Schwarz_#272727" into a label and a swatch colour.
 *
 * The failure this exists to prevent is silent: handing the raw value to CSS
 * produces `background: Schwarz_#272727`, which browsers drop without an error
 * and render as a blank tile. So the parser has to be right, and anything it
 * cannot read has to degrade to a plain text label rather than to nothing.
 */
class ColorValueTest extends TestCase
{
    public function testAValueWithAHexCodeSplitsIntoNameAndColour(): void
    {
        $color = ColorValue::parse('Schwarz_#272727');

        $this->assertSame('Schwarz', $color->getName());
        $this->assertSame('#272727', $color->getHex());
        $this->assertTrue($color->hasHex());
    }

    /**
     * The hash belongs to the hex, because every caller puts the value straight
     * into CSS. Stripping it here would mean every caller adding it back.
     */
    public function testTheHexKeepsItsLeadingHash(): void
    {
        $this->assertSame('#fff', ColorValue::parse('Weiss_#fff')->getHex());
    }

    /**
     * @dataProvider hexLengthProvider
     */
    public function testShorthandAndAlphaHexCodesAreAccepted(string $value, string $expected): void
    {
        $this->assertSame($expected, ColorValue::parse($value)->getHex());
    }

    public function hexLengthProvider(): array
    {
        return [
            'three digit'  => ['Rot_#f00', '#f00'],
            'six digit'    => ['Rot_#ff0000', '#ff0000'],
            'eight digit'  => ['Rot_#ff0000cc', '#ff0000cc'],
            'mixed case'   => ['Rot_#FfAa00', '#FfAa00'],
        ];
    }

    /**
     * The name half is matched greedily, so a colour name that itself contains
     * an underscore survives - only the trailing _#hex is taken as the code.
     */
    public function testOnlyTheTrailingHexIsSplitOff(): void
    {
        $color = ColorValue::parse('Schwarz_Meliert_#272727');

        $this->assertSame('Schwarz_Meliert', $color->getName());
        $this->assertSame('#272727', $color->getHex());
    }

    /**
     * Unexpected data must still produce a usable label. Returning null or
     * throwing here would empty a facet the merchant can see is not empty.
     *
     * @dataProvider unparsableProvider
     */
    public function testAnythingElseKeepsTheWholeValueAsItsName(string $value): void
    {
        $color = ColorValue::parse($value);

        $this->assertSame(trim($value), $color->getName());
        $this->assertNull($color->getHex());
        $this->assertFalse($color->hasHex());
    }

    public function unparsableProvider(): array
    {
        return [
            'plain name'        => ['Schwarz'],
            'no hash'           => ['Schwarz_272727'],
            'too short'         => ['Schwarz_#ff'],
            'too long'          => ['Schwarz_#ff0000ccdd'],
            'not hex'           => ['Schwarz_#gggggg'],
            'hex in the middle' => ['Schwarz_#272727_matt'],
            'empty'             => [''],
        ];
    }

    public function testSurroundingWhitespaceIsTrimmedFromBothHalves(): void
    {
        $color = ColorValue::parse('  Schwarz _#272727  ');

        $this->assertSame('Schwarz', $color->getName());
        $this->assertSame('#272727', $color->getHex());
    }

    public function testTheConstructorTakesTheTwoHalvesDirectly(): void
    {
        $color = new ColorValue('Rot', '#ff0000');

        $this->assertSame('Rot', $color->getName());
        $this->assertSame('#ff0000', $color->getHex());

        $this->assertFalse((new ColorValue('Rot', null))->hasHex());
    }
}
