<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

/**
 * Collapses colour values into the handful of colours a customer filters by.
 *
 * **Assumes this customer's data shape.** The colour attribute delivers values
 * as "Name_#hexcode" - see ColorValue - and the ERP has already snapped every
 * colour to a CSS colour name with its canonical hex. That is why the display
 * mode carrying this is named after the customer: on a shop whose colours come
 * through differently, the grouping does not apply, and a value that carries no
 * hex is deliberately left untouched rather than guessed at.
 *
 * The problem it solves is fragmentation, measured on the live catalogue: 187
 * distinct colour values over only 128 distinct hex codes, because the same
 * colour arrives in two languages ("black_#000000" and "Schwarz_#000000" are
 * one colour) and under ERP names ("Tomatencreme_#FFE4C4" is bisque). A sidebar
 * built from that shows three blacks and thirty variations of beige, none of
 * which a customer wants to choose between.
 *
 * Grouping on the **hex** rather than the name is what makes it language
 * independent: the German values carry the same codes as their English
 * counterparts, so both land in the same group without a translation table.
 *
 * The thresholds below were tuned against all 128 codes in the catalogue, and
 * the comments mark the cases where plain hue arithmetic gets it wrong -
 * cornsilk is cream rather than yellow, rosybrown is a skin tone rather than
 * red, crimson is red rather than pink, purple and deeppink share a hue and
 * belong in different groups.
 */
class ColorGrouper
{
    /**
     * Group label and swatch, in the order the sidebar should offer them.
     *
     * The swatch is a representative colour rather than any member's own code:
     * one tile has to stand for a whole family.
     *
     * @var array<string, array{label: string, hex: string}>
     */
    public const GROUPS = [
        'schwarz' => ['label' => 'Schwarz', 'hex' => '#000000'],
        'weiss' => ['label' => 'Weiß', 'hex' => '#FFFFFF'],
        'grau' => ['label' => 'Grau', 'hex' => '#9E9E9E'],
        'beige' => ['label' => 'Beige', 'hex' => '#E8CFAF'],
        'braun' => ['label' => 'Braun', 'hex' => '#8B5A2B'],
        'rot' => ['label' => 'Rot', 'hex' => '#D32F2F'],
        'rosa' => ['label' => 'Rosa', 'hex' => '#E91E63'],
        'orange' => ['label' => 'Orange', 'hex' => '#FB8C00'],
        'gelb' => ['label' => 'Gelb', 'hex' => '#FDD835'],
        'gruen' => ['label' => 'Grün', 'hex' => '#43A047'],
        'blau' => ['label' => 'Blau', 'hex' => '#1E88E5'],
        'lila' => ['label' => 'Lila', 'hex' => '#8E24AA'],
    ];

    /**
     * The grouped value, in the same "Name_#hexcode" shape the attribute
     * already uses - so everything downstream, the swatch parsing included,
     * keeps working without knowing that grouping happened.
     *
     * Returns null when the value carries no hex code to judge. The caller then
     * keeps the original, which is the honest outcome on data this was not
     * built for.
     */
    public function group(string $value): ?string
    {
        $color = ColorValue::parse($value);
        $hex = $color->getHex();

        if ($hex === null) {
            return null;
        }

        $slug = $this->classify($hex);

        if ($slug === null) {
            return null;
        }

        return self::GROUPS[$slug]['label'] . '_' . self::GROUPS[$slug]['hex'];
    }

    /**
     * Which group a hex code belongs to, or null when it cannot be read.
     */
    public function classify(string $hex): ?string
    {
        $channels = $this->toChannels($hex);

        if ($channels === null) {
            return null;
        }

        [$red, $green, $blue] = $channels;

        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $chroma = $max - $min;
        $lightness = ($max + $min) / 2;
        $saturation = $this->toSaturation($chroma, $lightness);
        $hue = $this->toHue($red, $green, $blue, $max, $chroma);

        // Black, white and grey are decided by how light the colour is; a tint
        // of hue in them is noise rather than a colour.
        if ($lightness <= 0.16) {
            return 'schwarz';
        }

        if ($lightness >= 0.93 && $chroma <= 0.10) {
            return 'weiss';
        }

        if ($chroma <= 0.10) {
            return 'grau';
        }

        // Very light with a little colour left in it is cream, not a pale
        // version of the hue it leans towards - cornsilk and papayawhip are
        // nudes, not yellows.
        if ($lightness >= 0.90 && $chroma <= 0.22) {
            return 'beige';
        }

        if ($hue < 15 || $hue >= 330) {
            return $this->classifyRed($hue, $saturation, $lightness);
        }

        if ($hue < 45) {
            return $this->classifyWarm($saturation, $lightness);
        }

        if ($hue < 70) {
            return $lightness >= 0.85 && $saturation <= 0.60 ? 'beige' : 'gelb';
        }

        if ($hue < 165) {
            return 'gruen';
        }

        if ($hue < 255) {
            return 'blau';
        }

        if ($hue < 290) {
            return 'lila';
        }

        // Magenta: dark is violet, light is pink. purple and deeppink sit on
        // the same hue and belong in different groups.
        return $lightness <= 0.35 ? 'lila' : 'rosa';
    }

    /**
     * The red end of the wheel, where this catalogue keeps its skin tones.
     */
    protected function classifyRed(float $hue, float $saturation, float $lightness): string
    {
        // Washed out reds are the nude family - rosybrown and mistyrose are
        // what a lingerie catalogue calls skin.
        if ($saturation <= 0.35 && $lightness >= 0.55) {
            return 'beige';
        }

        if ($lightness >= 0.80 && $saturation <= 0.90) {
            return $hue < 15 ? 'beige' : 'rosa';
        }

        if ($hue >= 330) {
            // Deep and saturated on the red side of pink is crimson.
            return $saturation >= 0.60 && $lightness <= 0.55 ? 'rot' : 'rosa';
        }

        return 'rot';
    }

    /**
     * Warm mid tones: pale ones are nudes, saturated bright ones are orange,
     * what is left is brown.
     */
    protected function classifyWarm(float $saturation, float $lightness): string
    {
        if ($lightness >= 0.75) {
            return 'beige';
        }

        if ($saturation >= 0.75 && $lightness >= 0.45) {
            return 'orange';
        }

        return 'braun';
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null Red, green and blue as 0..1
     */
    protected function toChannels(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');

        // Three digit shorthand, as CSS allows it.
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) < 6 || preg_match('/^[0-9a-fA-F]{6}/', $hex) !== 1) {
            return null;
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    protected function toSaturation(float $chroma, float $lightness): float
    {
        if ($chroma === 0.0) {
            return 0.0;
        }

        return $chroma / (1 - abs(2 * $lightness - 1));
    }

    protected function toHue(float $red, float $green, float $blue, float $max, float $chroma): float
    {
        if ($chroma === 0.0) {
            return 0.0;
        }

        if ($max === $red) {
            $hue = fmod(($green - $blue) / $chroma, 6);
        } elseif ($max === $green) {
            $hue = (($blue - $red) / $chroma) + 2;
        } else {
            $hue = (($red - $green) / $chroma) + 4;
        }

        $hue *= 60;

        return $hue < 0 ? $hue + 360 : $hue;
    }
}
