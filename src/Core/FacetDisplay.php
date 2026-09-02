<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use foun10\EasySearch\Engine\Result\Facet;

/**
 * How a facet is rendered, chosen per attribute in the admin.
 *
 * This used to be guessed: the builder rendered swatches whenever a value
 * happened to parse as "Name_#hexcode", and the indexer recognised the colour
 * attribute by a hard coded ID. Both broke the moment the ERP renamed or added
 * an attribute, and neither could be corrected without a deployment.
 *
 * A merchant now says what an attribute is. The list is meant to grow - a
 * numeric attribute rendered as a range slider is the obvious next one - which
 * is why this is a named mode per attribute rather than a boolean "is colour".
 *
 * Modes map onto Facet::TYPE_*, which is what the templates switch on. Keeping
 * the two apart leaves room for several configured modes to share one rendering,
 * and for a mode to mean something at index time as well as at query time.
 */
class FacetDisplay
{
    /**
     * A plain list of values. What almost every attribute wants.
     */
    public const MODE_DEFAULT = 'default';

    /**
     * Values carry a colour, written by the ERP as "Schwarz_#272727". The name
     * becomes the label and the code becomes the swatch.
     */
    public const MODE_COLOR = 'color';

    /**
     * Colour tiles as above, plus the values collapsed into colour groups while
     * indexing - three blacks and thirty beiges become one of each.
     *
     * The grouping reads the hex code out of "Name_#hexcode" and assumes the
     * source data has snapped colours to a known palette; see ColorGrouper. A
     * shop whose colour data does not work that way picks MODE_COLOR and gets
     * the values as they arrive.
     */
    public const MODE_GROUPED_COLOR_TILE = 'groupedcolortile';

    public const MODES = [
        self::MODE_DEFAULT,
        self::MODE_COLOR,
        self::MODE_GROUPED_COLOR_TILE,
    ];

    /**
     * Translation ident per mode, for the admin select.
     */
    protected const LABELS = [
        self::MODE_DEFAULT => 'FOUN10_EASYSEARCH_ADMIN_DISPLAY_DEFAULT',
        self::MODE_COLOR => 'FOUN10_EASYSEARCH_ADMIN_DISPLAY_COLOR',
        self::MODE_GROUPED_COLOR_TILE => 'FOUN10_EASYSEARCH_ADMIN_DISPLAY_GROUPEDCOLORTILE',
    ];

    /**
     * Anything unknown - a mode removed from the code while still configured in
     * a database - falls back to the plain list rather than disappearing.
     */
    public static function normalize(?string $mode): string
    {
        $mode = (string) $mode;

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_DEFAULT;
    }

    /**
     * Both colour modes render as swatches and both carry "Name_#hexcode", so
     * everything that splits a value asks this rather than comparing modes.
     */
    public static function isColor(?string $mode): bool
    {
        $mode = self::normalize($mode);

        return $mode === self::MODE_COLOR || $mode === self::MODE_GROUPED_COLOR_TILE;
    }

    /**
     * Whether the values of this attribute are collapsed into colour groups
     * while indexing. Asked by DocumentProvider, not by the frontend - by the
     * time a facet is rendered the grouping has already happened.
     */
    public static function isColorGrouped(?string $mode): bool
    {
        return self::normalize($mode) === self::MODE_GROUPED_COLOR_TILE;
    }

    /**
     * The facet type the templates render from.
     */
    public static function toFacetType(?string $mode): string
    {
        return self::isColor($mode) ? Facet::TYPE_COLOR : Facet::TYPE_LIST;
    }

    public static function getLabelIdent(string $mode): string
    {
        return self::LABELS[$mode] ?? self::LABELS[self::MODE_DEFAULT];
    }
}
