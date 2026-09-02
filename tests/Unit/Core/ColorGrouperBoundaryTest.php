<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Core;

use foun10\EasySearch\Core\ColorGrouper;
use PHPUnit\Framework\TestCase;

/**
 * The thresholds inside the classifier, tested one step either side.
 *
 * Every branch in here is a bare numeric comparison - lightness <= 0.16,
 * chroma <= 0.10, hue < 45 - and a test that only uses obvious colours from the
 * middle of each band never touches them. Changing 0.16 to 0.17, or < to <=,
 * would not fail anything, while quietly moving a slice of the catalogue into a
 * different filter after the next reindex.
 *
 * The values below were derived by measurement rather than by arithmetic, so
 * they say what the classifier really does at its edges.
 */
class ColorGrouperBoundaryTest extends TestCase
{
    private ColorGrouper $grouper;

    protected function setUp(): void
    {
        $this->grouper = new ColorGrouper();
    }

    /**
     * Lightness decides black before anything else is considered.
     */
    public function testTheBlackThreshold(): void
    {
        $this->assertSame('schwarz', $this->grouper->classify('#282828'), 'just inside');
        $this->assertSame('grau', $this->grouper->classify('#292929'), 'one step lighter is already grey');
    }

    /**
     * White needs both a high lightness and almost no colour left.
     */
    public function testTheWhiteThreshold(): void
    {
        $this->assertSame('weiss', $this->grouper->classify('#EEEEEE'), 'just inside');
        $this->assertSame('grau', $this->grouper->classify('#EDEDED'), 'one step darker is grey');
    }

    /**
     * Below this much colour a value is grey whatever its hue says - a tint in
     * a near-neutral is noise, not a colour.
     */
    public function testTheGreyChromaThreshold(): void
    {
        $this->assertSame('grau', $this->grouper->classify('#998080'), 'just inside');
        $this->assertSame('beige', $this->grouper->classify('#9A8080'), 'one step more colour and the hue decides');
    }

    /**
     * Very light with a little colour is a nude rather than a pale version of
     * the hue it leans towards.
     */
    public function testTheBeigeLightnessThreshold(): void
    {
        $this->assertSame('beige', $this->grouper->classify('#FFCCCC'), 'just inside');
        $this->assertSame('rot', $this->grouper->classify('#FFCBCB'), 'one step darker reads as a pale red');
    }

    /**
     * Each hue band, one step either side of its edge, at a saturation and
     * lightness that keep every other rule out of the way.
     *
     * @dataProvider hueBandProvider
     */
    public function testTheHueBandEdges(string $below, string $expectedBelow, string $above, string $expectedAbove): void
    {
        $this->assertSame($expectedBelow, $this->grouper->classify($below));
        $this->assertSame($expectedAbove, $this->grouper->classify($above));
    }

    public function hueBandProvider(): array
    {
        return [
            'red to orange at 15'    => ['#E63C1A', 'rot',   '#E65E1A', 'orange'],
            'orange to yellow at 45' => ['#E6A21A', 'orange', '#E6C41A', 'gelb'],
            'yellow to green at 70'  => ['#D5E61A', 'gelb',  '#B3E61A', 'gruen'],
            'green to blue at 165'   => ['#1AE6A2', 'gruen', '#1AE6C4', 'blau'],
            'blue to violet at 255'  => ['#3C1AE6', 'blau',  '#5D1AE6', 'lila'],
            'violet to pink at 290'  => ['#B31AE6', 'lila',  '#D51AE6', 'rosa'],
            'pink to red at 330'     => ['#E61A91', 'rosa',  '#E61A6F', 'rot'],
        ];
    }

    /**
     * The warm band splits three ways by lightness and saturation: pale is a
     * nude, bright is orange, and what is left is brown.
     */
    public function testTheWarmBandSplitsIntoNudeOrangeAndBrown(): void
    {
        $this->assertSame('beige', $this->grouper->classify('#F5DEB3'), 'wheat');
        $this->assertSame('orange', $this->grouper->classify('#FF8C00'), 'darkorange');
        $this->assertSame('braun', $this->grouper->classify('#D2691E'), 'chocolate');
    }

    /**
     * Yellow does the same at its pale end - cream is not a washed out yellow.
     */
    public function testPaleYellowIsCreamRatherThanYellow(): void
    {
        $this->assertSame('beige', $this->grouper->classify('#FAFAD2'), 'lightgoldenrodyellow');
        $this->assertSame('gelb', $this->grouper->classify('#F0E68C'), 'khaki is still yellow');
        $this->assertSame('gelb', $this->grouper->classify('#FFD700'), 'gold');
    }

    /**
     * Purple and deeppink sit on the same hue; only lightness separates them.
     */
    public function testTheMagentaTailSplitsByLightness(): void
    {
        $this->assertSame('lila', $this->grouper->classify('#800080'), 'purple');
        $this->assertSame('rosa', $this->grouper->classify('#FF1493'), 'deeppink');
    }

    /**
     * The red end is where a clothing catalogue keeps its skin tones, so a
     * washed out red is a nude rather than a pale red.
     */
    public function testWashedOutRedsAreTheNudeFamily(): void
    {
        $this->assertSame('beige', $this->grouper->classify('#BC8F8F'), 'rosybrown');
        $this->assertSame('beige', $this->grouper->classify('#FFE4E1'), 'mistyrose');
        $this->assertSame('rot', $this->grouper->classify('#FA8072'), 'salmon is still red');
        $this->assertSame('rot', $this->grouper->classify('#DC143C'), 'crimson');
    }

    /**
     * Twelve buckets cannot hold every colour name, and these are the calls the
     * classifier makes. Pinned so a change to them is a decision rather than an
     * accident - not because any of them is the only defensible answer.
     *
     * @dataProvider judgementCallProvider
     */
    public function testDocumentedJudgementCalls(string $hex, string $group): void
    {
        $this->assertSame($group, $this->grouper->classify($hex));
    }

    public function judgementCallProvider(): array
    {
        return [
            'teal has no bucket of its own'   => ['#008080', 'blau'],
            'olive lands in yellow'           => ['#808000', 'gelb'],
            'tan is dark enough to be brown'  => ['#D2B48C', 'braun'],
            'lavender is light enough to be white' => ['#E6E6FA', 'weiss'],
        ];
    }

    /**
     * Shorthand hex is accepted the way CSS allows it, so #f00 and #ff0000 must
     * not land in different groups.
     */
    public function testShorthandHexIsExpandedBeforeClassifying(): void
    {
        $this->assertSame($this->grouper->classify('#ff0000'), $this->grouper->classify('#f00'));
        $this->assertSame($this->grouper->classify('#000000'), $this->grouper->classify('#000'));
    }

    /**
     * The hash is optional and surrounding whitespace is tolerated, because the
     * value arrives from catalogue data rather than from a colour picker.
     */
    public function testTheHashAndSurroundingWhitespaceAreOptional(): void
    {
        $this->assertSame('rot', $this->grouper->classify('ff0000'));
        $this->assertSame('rot', $this->grouper->classify('  #ff0000  '));
    }

    /**
     * @dataProvider unreadableProvider
     */
    public function testUnreadableInputIsNotGuessedAt(string $hex): void
    {
        $this->assertNull($this->grouper->classify($hex));
    }

    public function unreadableProvider(): array
    {
        return [
            'empty'        => [''],
            'too short'    => ['#ff'],
            'not hex'      => ['#gggggg'],
            'a word'       => ['schwarz'],
            'four digits'  => ['#ff00'],
        ];
    }
}
