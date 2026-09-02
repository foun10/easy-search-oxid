<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Correction;

use foun10\EasySearch\Correction\ColognePhonetic;
use PHPUnit\Framework\TestCase;

/**
 * Kölner Phonetik, which is what lets a correction reach a word that is spelt
 * differently but sounds the same - the case edit distance alone is bad at.
 *
 * The expectations below are the algorithm's published values, not whatever
 * this implementation currently returns. A test that only pins current output
 * would happily preserve a bug.
 *
 * Input is always already normalised, so umlauts arrive expanded ("mueller",
 * not "müller") and everything is lowercase.
 */
class ColognePhoneticTest extends TestCase
{
    private ColognePhonetic $phonetic;

    protected function setUp(): void
    {
        $this->phonetic = new ColognePhonetic();
    }

    /**
     * @dataProvider referenceProvider
     */
    public function testKnownWordsEncodeToTheirPublishedCode(string $term, string $expected): void
    {
        $this->assertSame($expected, $this->phonetic->encode($term));
    }

    public function referenceProvider(): array
    {
        return [
            'mueller'   => ['mueller', '657'],
            'meyer'     => ['meyer', '67'],
            'mayr'      => ['mayr', '67'],
            'wikipedia' => ['wikipedia', '3412'],
            'rot'       => ['rot', '72'],
            'breit'     => ['breit', '172'],
        ];
    }

    /**
     * The point of the encoder: two spellings of the same sound share a code,
     * so the corrector can reach across a spelling difference that a plain
     * edit distance would rank far apart.
     */
    public function testWordsThatSoundAlikeShareACode(): void
    {
        $this->assertSame(
            $this->phonetic->encode('meyer'),
            $this->phonetic->encode('mayr')
        );
    }

    public function testWordsThatSoundDifferentDoNot(): void
    {
        $this->assertNotSame(
            $this->phonetic->encode('spitze'),
            $this->phonetic->encode('breit')
        );
    }

    /**
     * Vowels carry code 0, which is dropped everywhere except at the very
     * front - so a leading vowel stays visible in the code and "aal" does not
     * collapse to a bare "5".
     */
    public function testALeadingVowelIsKeptWhileInnerOnesAreDropped(): void
    {
        $this->assertSame('05', $this->phonetic->encode('aal'));
        $this->assertSame('657', $this->phonetic->encode('mueller'), 'inner vowels contribute nothing');
    }

    public function testRepeatedCodesCollapse(): void
    {
        // ll is one 5, not two.
        $this->assertSame($this->phonetic->encode('muler'), $this->phonetic->encode('mueller'));
    }

    /**
     * "ph" is an f sound, not p followed by h.
     */
    public function testPhIsTreatedAsAnFSound(): void
    {
        $this->assertSame('36', $this->phonetic->encode('phon'));
        $this->assertSame($this->phonetic->encode('phon'), $this->phonetic->encode('fon'));
    }

    /**
     * Every letter's own code, one case per branch of the encoder.
     *
     * Grouped cases like "a, e, i, j, o, u, y all mean 0" are exactly what a
     * mutation run picks apart: drop one label and the remaining tests still
     * pass, because they only ever exercise two of the seven.
     *
     * @dataProvider letterProvider
     */
    public function testEachLetterContributesItsOwnCode(string $term, string $expected): void
    {
        $this->assertSame($expected, $this->phonetic->encode($term));
    }

    public function letterProvider(): array
    {
        $cases = [];

        // Vowels and the letters treated as vowels: leading 0, then l = 5.
        foreach (['a', 'e', 'i', 'j', 'o', 'u', 'y'] as $vowel) {
            $cases['vowel ' . $vowel] = [$vowel . 'l', '05'];
        }

        // Consonants, read after a leading vowel so the code is 0 + the letter.
        $consonants = [
            'b' => '1', 'p' => '1',
            'd' => '2', 't' => '2',
            'f' => '3', 'v' => '3', 'w' => '3',
            'g' => '4', 'k' => '4', 'q' => '4',
            'l' => '5',
            'm' => '6', 'n' => '6',
            'r' => '7',
            's' => '8', 'z' => '8',
        ];

        foreach ($consonants as $letter => $code) {
            $cases['consonant ' . $letter] = ['a' . $letter, '0' . $code];
        }

        return $cases;
    }

    /**
     * h carries no code of its own - it only modifies the letter before it.
     */
    public function testHIsSilentOnItsOwn(): void
    {
        $this->assertSame('05', $this->phonetic->encode('ahl'));
    }

    /**
     * d and t become a sibilant before c, s or z instead of their usual 2.
     */
    public function testDAndTBecomeSibilantsBeforeASibilant(): void
    {
        $this->assertSame('8', $this->phonetic->encode('tz'));
        $this->assertSame('27', $this->phonetic->encode('tr'), 'and stay a 2 otherwise');
    }

    /**
     * c is the awkward one: hard or soft depending on what surrounds it, and
     * on whether it opens the word.
     */
    public function testCIsHardOrSoftDependingOnItsNeighbours(): void
    {
        $this->assertSame('4', $this->phonetic->encode('ca'), 'initial c before a hard follower');
        $this->assertSame('8', $this->phonetic->encode('ce'), 'initial c otherwise');
        $this->assertSame('04', $this->phonetic->encode('aca'), 'inner c before a hard follower');
        $this->assertSame('08', $this->phonetic->encode('ace'), 'inner c otherwise');
        $this->assertSame('8', $this->phonetic->encode('sch'), 'after a sibilant it is always soft');
    }

    /**
     * x is a k plus an s, unless the k sound is already there.
     */
    public function testXIsAPairUnlessTheKSoundPrecedesIt(): void
    {
        $this->assertSame('048', $this->phonetic->encode('ax'));
        $this->assertSame('48', $this->phonetic->encode('kx'), 'the k is already counted');
    }

    public function testAnEmptyTermEncodesToAnEmptyCode(): void
    {
        $this->assertSame('', $this->phonetic->encode(''));
    }

    /**
     * Digits reach the encoder from article numbers and sizes. They carry no
     * sound and must not invent one.
     */
    public function testDigitsContributeNothing(): void
    {
        $this->assertSame($this->phonetic->encode('rot'), $this->phonetic->encode('rot38'));
    }
}
