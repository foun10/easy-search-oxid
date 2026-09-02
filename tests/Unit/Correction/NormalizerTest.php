<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Correction;

use foun10\EasySearch\Correction\Normalizer;
use PHPUnit\Framework\TestCase;

/**
 * Normalisation is the join between three things that must agree: what the
 * indexer wrote, what the dictionary holds, and what a customer typed. If any
 * of them normalises differently the search silently finds nothing, so these
 * tests pin the exact output rather than "something reasonable".
 */
class NormalizerTest extends TestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new Normalizer();
    }

    /**
     * The order matters and is easy to get wrong: folding accents first would
     * turn "ü" into "u", and "buegel" would stop matching "bügel".
     *
     * @dataProvider umlautProvider
     */
    public function testUmlautsExpandBeforeAccentsAreFolded(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    public function umlautProvider(): array
    {
        return [
            'lowercase umlaut'     => ['bügel', 'buegel'],
            'uppercase umlaut'     => ['Bügel', 'buegel'],
            'sharp s'              => ['weiß', 'weiss'],
            'already expanded'     => ['buegel', 'buegel'],
            'o umlaut'             => ['größe', 'groesse'],
            'a umlaut'             => ['träger', 'traeger'],
        ];
    }

    /**
     * @dataProvider accentProvider
     */
    public function testAccentsAreFoldedToPlainLetters(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    public function accentProvider(): array
    {
        return [
            'french e'  => ['crème', 'creme'],
            'spanish n' => ['piña', 'pina'],
            'cedilla'   => ['françois', 'francois'],
            'slashed o' => ['søster', 'soster'],
        ];
    }

    /**
     * A hyphen must separate rather than glue, or "Push-Up" would index as one
     * token and never match somebody typing the two words.
     */
    public function testPunctuationBecomesASeparator(): void
    {
        $this->assertSame('push up', $this->normalizer->normalize('Push-Up'));
        $this->assertSame('bh set 2 teilig', $this->normalizer->normalize('BH-Set, 2-teilig!'));
    }

    public function testWhitespaceIsCollapsedAndTrimmed(): void
    {
        $this->assertSame('rot blau', $this->normalizer->normalize("  rot \t\n  blau  "));
    }

    public function testDigitsSurvive(): void
    {
        $this->assertSame('groesse 38', $this->normalizer->normalize('Größe 38'));
    }

    public function testAnEmptyOrSymbolOnlyValueNormalisesToEmpty(): void
    {
        $this->assertSame('', $this->normalizer->normalize(''));
        $this->assertSame('', $this->normalizer->normalize('!!! ???'));
    }

    public function testTokenizeDropsShortTokensStopwordsAndDuplicates(): void
    {
        $this->assertSame(
            ['bh', 'spitze'],
            $this->normalizer->tokenize('BH und die Spitze und BH')
        );
    }

    public function testTokenizeRespectsTheMinimumLength(): void
    {
        $this->assertSame(['spitze'], $this->normalizer->tokenize('bh spitze', 3));
    }

    public function testTokenizeReturnsAListNotAGappedArray(): void
    {
        $tokens = $this->normalizer->tokenize('der BH und die Spitze');

        $this->assertSame([0, 1], array_keys($tokens), 'json_encode would turn a gapped array into an object');
    }

    public function testTokenizeOfNothingIsAnEmptyList(): void
    {
        $this->assertSame([], $this->normalizer->tokenize('!!!'));
        $this->assertSame([], $this->normalizer->tokenize(''));
    }

    /**
     * The bucket narrows the dictionary before the distance calculation runs.
     * Two characters, taken multibyte-safe - substr() would split a UTF-8 code
     * point and bucket the term under a byte fragment nothing else shares.
     */
    public function testTheBucketIsTheFirstTwoCharacters(): void
    {
        $this->assertSame('bh', $this->normalizer->getBucket('bh'));
        $this->assertSame('sp', $this->normalizer->getBucket('spitze'));
        $this->assertSame('a', $this->normalizer->getBucket('a'));
        $this->assertSame('', $this->normalizer->getBucket(''));
    }

    public function testStopwordsAreRecognisedInBothLanguages(): void
    {
        $this->assertTrue($this->normalizer->isStopword('und'));
        $this->assertTrue($this->normalizer->isStopword('the'));
        $this->assertFalse($this->normalizer->isStopword('bh'));
    }

    /**
     * "in" is a stopword in German and a product name fragment in a fashion
     * catalogue. It is deliberately absent from the list, and that is worth
     * pinning so nobody "improves" the list later without noticing.
     */
    public function testTheStopwordListStaysDeliberatelyShort(): void
    {
        $this->assertFalse($this->normalizer->isStopword('in'));
        $this->assertFalse($this->normalizer->isStopword('set'));
    }

    /**
     * Boolean fulltext mode reads these as operators. Left in place, "bh +"
     * is at best a syntax error and at worst a query that quietly means
     * something else than what was typed.
     *
     * @dataProvider operatorProvider
     */
    public function testFulltextOperatorsAreStripped(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->stripFulltextOperators($input));
    }

    public function operatorProvider(): array
    {
        return [
            'plus'      => ['bh +', 'bh  '],
            'minus'     => ['bh -spitze', 'bh  spitze'],
            'wildcard'  => ['spitz*', 'spitz '],
            'quotes'    => ['"push up"', ' push up '],
            'grouping'  => ['(bh spitze)', ' bh spitze '],
            'distance'  => ['bh @2', 'bh  2'],
            'untouched' => ['bh spitze', 'bh spitze'],
        ];
    }
}
