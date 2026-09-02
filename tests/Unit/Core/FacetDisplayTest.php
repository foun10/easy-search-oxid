<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Core;

use foun10\EasySearch\Core\FacetDisplay;
use foun10\EasySearch\Engine\Result\Facet;
use PHPUnit\Framework\TestCase;

/**
 * How a facet renders, chosen per attribute in the admin and stored as a
 * string in the attribute table.
 *
 * Because the value is stored, the constants are part of the data format, not
 * just of the code: renaming one without a migration leaves every configured
 * attribute pointing at a mode that no longer exists. The literal values are
 * pinned here for that reason.
 */
class FacetDisplayTest extends TestCase
{
    /**
     * @dataProvider storedValueProvider
     */
    public function testTheStoredValuesAreStable(string $constant, string $expected): void
    {
        $this->assertSame($expected, $constant, 'this string is in the database, not only in the code');
    }

    public function storedValueProvider(): array
    {
        return [
            'default' => [FacetDisplay::MODE_DEFAULT, 'default'],
            'color'   => [FacetDisplay::MODE_COLOR, 'color'],
            'grouped' => [FacetDisplay::MODE_GROUPED_COLOR_TILE, 'groupedcolortile'],
        ];
    }

    public function testEveryModeIsListed(): void
    {
        $this->assertSame(
            [FacetDisplay::MODE_DEFAULT, FacetDisplay::MODE_COLOR, FacetDisplay::MODE_GROUPED_COLOR_TILE],
            FacetDisplay::MODES
        );
    }

    /**
     * A mode removed from the code while still configured in a database must
     * fall back to the plain list rather than making the facet disappear.
     *
     * @dataProvider unknownModeProvider
     */
    public function testAnythingUnknownNormalisesToThePlainList(?string $mode): void
    {
        $this->assertSame(FacetDisplay::MODE_DEFAULT, FacetDisplay::normalize($mode));
    }

    public function unknownModeProvider(): array
    {
        return [
            'null'            => [null],
            'empty'           => [''],
            'retired mode'    => ['legacycolortile'],
            'typo'            => ['colour'],
            'wrong case'      => ['COLOR'],
        ];
    }

    public function testAKnownModeSurvivesNormalisation(): void
    {
        foreach (FacetDisplay::MODES as $mode) {
            $this->assertSame($mode, FacetDisplay::normalize($mode));
        }
    }

    /**
     * Both colour modes carry "Name_#hexcode", so everything that splits a
     * value asks this rather than comparing modes one by one.
     */
    public function testBothColourModesCountAsColour(): void
    {
        $this->assertTrue(FacetDisplay::isColor(FacetDisplay::MODE_COLOR));
        $this->assertTrue(FacetDisplay::isColor(FacetDisplay::MODE_GROUPED_COLOR_TILE));
        $this->assertFalse(FacetDisplay::isColor(FacetDisplay::MODE_DEFAULT));
        $this->assertFalse(FacetDisplay::isColor(null));
    }

    /**
     * Only the grouped mode collapses values while indexing. Confusing the two
     * would either group colours nobody asked to group, or stop grouping the
     * ones that were configured for it - and both only show after a rebuild.
     */
    public function testOnlyTheGroupedModeGroupsWhileIndexing(): void
    {
        $this->assertTrue(FacetDisplay::isColorGrouped(FacetDisplay::MODE_GROUPED_COLOR_TILE));
        $this->assertFalse(FacetDisplay::isColorGrouped(FacetDisplay::MODE_COLOR));
        $this->assertFalse(FacetDisplay::isColorGrouped(FacetDisplay::MODE_DEFAULT));
        $this->assertFalse(FacetDisplay::isColorGrouped(null));
    }

    /**
     * Several configured modes deliberately share one rendering, which is why
     * the mode and the facet type are kept apart.
     */
    public function testModesMapOntoTheFacetTypeTheTemplatesSwitchOn(): void
    {
        $this->assertSame(Facet::TYPE_COLOR, FacetDisplay::toFacetType(FacetDisplay::MODE_COLOR));
        $this->assertSame(Facet::TYPE_COLOR, FacetDisplay::toFacetType(FacetDisplay::MODE_GROUPED_COLOR_TILE));
        $this->assertSame(Facet::TYPE_LIST, FacetDisplay::toFacetType(FacetDisplay::MODE_DEFAULT));
        $this->assertSame(Facet::TYPE_LIST, FacetDisplay::toFacetType(null));
    }

    public function testEveryModeHasItsOwnLabelIdent(): void
    {
        $idents = array_map(
            static fn (string $mode): string => FacetDisplay::getLabelIdent($mode),
            FacetDisplay::MODES
        );

        $this->assertCount(count(FacetDisplay::MODES), array_unique($idents), 'no two modes share a label');

        foreach ($idents as $ident) {
            $this->assertStringStartsWith('FOUN10_EASYSEARCH_', $ident);
        }
    }

    public function testAnUnknownModeFallsBackToTheDefaultLabel(): void
    {
        $this->assertSame(
            FacetDisplay::getLabelIdent(FacetDisplay::MODE_DEFAULT),
            FacetDisplay::getLabelIdent('no-such-mode')
        );
    }
}
