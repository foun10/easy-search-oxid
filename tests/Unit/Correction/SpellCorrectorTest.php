<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Correction;

use foun10\EasySearch\Correction\ColognePhonetic;
use foun10\EasySearch\Correction\DamerauLevenshtein;
use foun10\EasySearch\Correction\Normalizer;
use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Synonym\SynonymExpander;
use foun10\EasySearch\Tests\Unit\Double\TestableSpellCorrector;
use PHPUnit\Framework\TestCase;

/**
 * The rules that decide whether a customer's word gets replaced.
 *
 * Every one of these is a silent decision: nobody sees a correction being
 * declined, they just see the results of whatever was searched. The two data
 * access methods are stubbed in TestableSpellCorrector, because the interesting
 * cases - a tie between two candidates of equal distance, a word protected by a
 * synonym rule - are far easier to state than to arrange in a real dictionary.
 */
class SpellCorrectorTest extends TestCase
{
    private const SHOP = 1;
    private const LANG = 0;

    private function corrector(
        bool $enabled = true,
        int $minTermLength = 2,
        int $minFrequency = 2,
        array $knownWords = []
    ): TestableSpellCorrector {
        $settings = $this->createMock(ModuleSettings::class);
        $settings->method('isCorrectionEnabled')->willReturn($enabled);
        $settings->method('getMinTermLength')->willReturn($minTermLength);
        $settings->method('getCorrectionMinFrequency')->willReturn($minFrequency);

        $expander = $this->createMock(SynonymExpander::class);
        $expander->method('isKnownWord')->willReturnCallback(
            static fn (string $word): bool => in_array($word, $knownWords, true)
        );

        return new TestableSpellCorrector(
            new Normalizer(),
            new DamerauLevenshtein(),
            new ColognePhonetic(),
            $settings,
            $expander
        );
    }

    /**
     * @param array<int, array{0: string, 1: int}> $rows
     */
    private function candidates(array $rows): array
    {
        return array_map(
            static fn (array $row): array => ['FOUN10TERM' => $row[0], 'FOUN10FREQUENCY' => $row[1]],
            $rows
        );
    }

    public function testATypoIsCorrectedTowardsTheDictionaryTerm(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = $this->candidates([['nachthemd', 40]]);

        $corrected = $corrector->correct('nachtemd', self::SHOP, self::LANG);

        $this->assertNotNull($corrected);
        $this->assertSame('nachtemd', $corrected->getOriginal());
        $this->assertSame('nachthemd', $corrected->getCorrected());
        $this->assertSame(1, $corrected->getMaxDistance());
        $this->assertSame(1, $corrected->getCorrectedTokenCount());
    }

    public function testNothingHappensWhenCorrectionIsSwitchedOff(): void
    {
        $corrector = $this->corrector(enabled: false);
        $corrector->candidates = $this->candidates([['nachthemd', 40]]);

        $this->assertNull($corrector->correct('nachtemd', self::SHOP, self::LANG));
    }

    /**
     * A word the dictionary already knows is spelt correctly by definition, so
     * it is never touched - and the candidate query is not even run.
     */
    public function testAWordTheDictionaryKnowsIsLeftAlone(): void
    {
        $corrector = $this->corrector();
        $corrector->knownTerms = ['spitze'];
        $corrector->candidates = $this->candidates([['spritze', 90]]);

        $this->assertNull($corrector->correct('spitze', self::SHOP, self::LANG));
        $this->assertSame([], $corrector->candidateCalls, 'no lookup needed for a known word');
    }

    /**
     * A word somebody wrote a synonym rule for is a real word by decision, even
     * when the catalogue never spells it out. Correcting it away would defeat
     * the rule written to catch exactly that search.
     */
    public function testAWordProtectedByASynonymRuleIsNeverCorrected(): void
    {
        $corrector = $this->corrector(knownWords: ['bralette']);
        $corrector->candidates = $this->candidates([['bralett', 80]]);

        $this->assertNull($corrector->correct('bralette', self::SHOP, self::LANG));
    }

    /**
     * Short words carry too little redundancy: one edit turns "rot" into
     * "rock", so they are not corrected at all and never reach the dictionary.
     */
    public function testShortWordsAreNotCorrected(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = $this->candidates([['rock', 500]]);

        $this->assertNull($corrector->correct('rot', self::SHOP, self::LANG));
        $this->assertSame([], $corrector->candidateCalls);
    }

    public function testTheClosestCandidateWins(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = $this->candidates([
            ['spritzen', 900],
            ['spitze', 10],
        ]);

        $corrected = $corrector->correct('spitzee', self::SHOP, self::LANG);

        $this->assertNotNull($corrected);
        $this->assertSame('spitze', $corrected->getCorrected(), 'distance beats frequency');
    }

    /**
     * At equal distance the more common word wins - which is the whole reason
     * the frequency is carried through the query at all.
     */
    public function testAtEqualDistanceTheMoreCommonWordWins(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = $this->candidates([
            ['bluse', 5],
            ['blume', 900],
        ]);

        $corrected = $corrector->correct('blupe', self::SHOP, self::LANG);

        $this->assertNotNull($corrected);
        $this->assertSame('blume', $corrected->getCorrected());
    }

    /**
     * The first candidate at the winning distance keeps its place unless a
     * later one is strictly better, so an equally distant and equally common
     * term does not flip the result on query order alone.
     */
    public function testAnEquallyGoodLaterCandidateDoesNotDisplaceTheFirst(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = $this->candidates([
            ['bluse', 50],
            ['blume', 50],
        ]);

        $corrected = $corrector->correct('blupe', self::SHOP, self::LANG);

        $this->assertNotNull($corrected);
        $this->assertSame('bluse', $corrected->getCorrected());
    }

    public function testCandidatesBeyondTheAllowedDistanceAreIgnored(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = $this->candidates([['strumpfhose', 900]]);

        $this->assertNull($corrector->correct('nachtemd', self::SHOP, self::LANG));
    }

    public function testAnEmptyDictionaryCorrectsNothing(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = [];

        $this->assertNull($corrector->correct('nachtemd', self::SHOP, self::LANG));
    }

    /**
     * A phrase is corrected token by token, and the words that were already
     * right are carried through untouched.
     */
    public function testOnlyTheWrongWordOfAPhraseIsReplaced(): void
    {
        $corrector = $this->corrector();
        $corrector->knownTerms = ['schwarz'];
        $corrector->candidates = $this->candidates([['nachthemd', 40]]);

        $corrected = $corrector->correct('nachtemd schwarz', self::SHOP, self::LANG);

        $this->assertNotNull($corrected);
        $this->assertSame('nachtemd schwarz', $corrected->getOriginal());
        $this->assertSame('nachthemd schwarz', $corrected->getCorrected());
        $this->assertSame(1, $corrected->getCorrectedTokenCount());
    }

    /**
     * Two wrong words in one phrase are both replaced, and the count reports
     * both - it is what the caller weighs when deciding whether to apply the
     * correction or merely offer it.
     */
    public function testEveryWrongWordOfAPhraseIsCounted(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = $this->candidates([['nachthemd', 40], ['bluse', 30]]);

        $corrected = $corrector->correct('nachtemd blusse', self::SHOP, self::LANG);

        $this->assertNotNull($corrected);
        $this->assertSame('nachthemd bluse', $corrected->getCorrected());
        $this->assertSame(2, $corrected->getCorrectedTokenCount());
        $this->assertSame(1, $corrected->getMaxDistance(), 'the worst single edit, not their sum');
    }

    /**
     * A word typed twice is one token, because tokenize() de-duplicates. So the
     * corrected phrase is shorter than what the customer typed, and the count
     * is one rather than two.
     *
     * Worth pinning: it means getCorrected() is not a rewrite of the input
     * string but a normalised phrase built from unique tokens, and anything
     * echoing it back to the customer has to expect that.
     */
    public function testARepeatedWordIsOneTokenAndCorrectedOnce(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = $this->candidates([['nachthemd', 40]]);

        $corrected = $corrector->correct('nachtemd nachtemd', self::SHOP, self::LANG);

        $this->assertNotNull($corrected);
        $this->assertSame('nachtemd', $corrected->getOriginal(), 'the duplicate is dropped before correcting');
        $this->assertSame('nachthemd', $corrected->getCorrected());
        $this->assertSame(1, $corrected->getCorrectedTokenCount());
    }

    public function testAPhraseThatNeedsNoCorrectionReturnsNull(): void
    {
        $corrector = $this->corrector();
        $corrector->knownTerms = ['bh', 'spitze'];

        $this->assertNull($corrector->correct('bh spitze', self::SHOP, self::LANG));
    }

    public function testAnEmptyOrSymbolOnlyTermIsNotCorrected(): void
    {
        $corrector = $this->corrector();

        $this->assertNull($corrector->correct('', self::SHOP, self::LANG));
        $this->assertNull($corrector->correct('!!!', self::SHOP, self::LANG));
    }

    /**
     * The scope reaches the dictionary query, or a subshop would be corrected
     * against another shop's vocabulary.
     */
    public function testTheShopAndLanguageReachTheDictionaryQuery(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = $this->candidates([['nachthemd', 40]]);

        $corrector->correct('nachtemd', 3, 2);

        $this->assertCount(1, $corrector->candidateCalls);
        $this->assertSame(3, $corrector->candidateCalls[0]['shopId']);
        $this->assertSame(2, $corrector->candidateCalls[0]['langId']);
        $this->assertSame('nachtemd', $corrector->candidateCalls[0]['token']);
    }

    /**
     * The distance budget handed to the query is the one the word's length
     * allows, so the SQL length window matches what the ranking will accept.
     */
    public function testTheDistanceBudgetMatchesTheWordLength(): void
    {
        $corrector = $this->corrector();
        $corrector->candidates = [];

        $corrector->correct('bluse', self::SHOP, self::LANG);
        $this->assertSame(1, $corrector->candidateCalls[0]['maxDistance'], 'five letters allow one edit');

        $corrector->candidateCalls = [];
        $corrector->correct('nachthemden', self::SHOP, self::LANG);
        $this->assertSame(2, $corrector->candidateCalls[0]['maxDistance'], 'eleven letters allow two');
    }
}
