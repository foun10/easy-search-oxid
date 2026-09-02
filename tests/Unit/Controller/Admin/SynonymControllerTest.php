<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Controller\Admin;

use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Core\SynonymConfiguration;
use foun10\EasySearch\Synonym\SynonymRule;
use foun10\EasySearch\Tests\Unit\Double\TestableSynonymController;
use PHPUnit\Framework\TestCase;

/**
 * The synonym screen.
 *
 * Two things make this more than a form. The scope, first: rules belong to a
 * subshop *and* a language, and the screen only ever has one language's list in
 * front of it - so a save that reached wider than that would silently throw
 * away rules nobody could see. Second, what arrives from the form is not what
 * goes into the table: OXID escapes request parameters into HTML entities on
 * the way in, and an apostrophe stored as `&#039;` comes back looking like that
 * for good.
 *
 * The rest is small courtesies that are easy to break and annoying to live
 * with: blank rows to type into, and a term handed over from the report screen
 * arriving pre-filled rather than needing to be typed a second time.
 */
class SynonymControllerTest extends TestCase
{
    private TestableSynonymController $controller;

    /** @var array<string, SynonymRule[]> Configured rules keyed "shopId_langId" */
    private array $configured = [];

    /** @var array<int, array{shopId: int, langId: int, entries: array<int, mixed>}> */
    private array $saves = [];

    private int $storedCount = 0;

    /** @var array<int, array<int, array{id: int, abbr: string, name: string}>> Keyed by shop id */
    private array $languages = [];

    protected function setUp(): void
    {
        $configuration = $this->createMock(SynonymConfiguration::class);
        $configuration->method('getRules')->willReturnCallback(
            fn (int $shopId, int $langId): array => $this->configured[$shopId . '_' . $langId] ?? []
        );
        $configuration->method('save')->willReturnCallback(
            function (int $shopId, int $langId, array $entries): int {
                $this->saves[] = ['shopId' => $shopId, 'langId' => $langId, 'entries' => $entries];

                return $this->storedCount;
            }
        );

        $shopLanguages = $this->createMock(ShopLanguages::class);
        $shopLanguages->method('getActive')->willReturnCallback(
            fn (?int $shopId = null): array => $this->languages[$shopId] ?? []
        );

        $this->controller = new TestableSynonymController();
        $this->controller->services = [
            SynonymConfiguration::class => $configuration,
            ShopLanguages::class => $shopLanguages,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function withRequest(array $parameters): void
    {
        $this->controller->request->escaped = $parameters;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function row(array $fields = []): array
    {
        return $fields + ['type' => 'both', 'term' => 'bh', 'synonyms' => 'bustier', 'active' => '1'];
    }

    // ---------------------------------------------------------------
    // saving, and what it is allowed to touch
    // ---------------------------------------------------------------

    /**
     * The screen submits one language's list and knows nothing about the
     * others, so the save has to name the scope it replaces.
     */
    public function testASaveNamesTheShopAndLanguageItReplaces(): void
    {
        $this->controller->currentShopId = 3;
        $this->withRequest(['synonymLang' => '2', 'rules' => [$this->row()]]);

        $this->controller->save();

        $this->assertSame(3, $this->saves[0]['shopId']);
        $this->assertSame(2, $this->saves[0]['langId']);
    }

    public function testTheSubmittedRulesAreWhatGetsSaved(): void
    {
        $this->withRequest(['rules' => [
            $this->row(['term' => 'bh', 'synonyms' => 'bustier']),
            $this->row(['term' => 'slip', 'synonyms' => 'panty', 'active' => '0']),
        ]]);

        $this->controller->save();

        $this->assertSame(
            [
                ['type' => 'both', 'term' => 'bh', 'synonyms' => 'bustier', 'active' => true],
                ['type' => 'both', 'term' => 'slip', 'synonyms' => 'panty', 'active' => false],
            ],
            $this->saves[0]['entries']
        );
    }

    /**
     * save() redirects into a fresh request, so the count has to survive in
     * the session or the screen cannot confirm anything.
     */
    public function testTheStoredCountIsLeftForTheRequestThatRendersTheList(): void
    {
        $this->storedCount = 7;
        $this->withRequest(['rules' => [$this->row()]]);

        $this->controller->save();

        $this->assertSame(7, $this->controller->getSavedCount());
    }

    // ---------------------------------------------------------------
    // what a submitted row means
    // ---------------------------------------------------------------

    public function testAFormThatPostedNoRulesSavesNone(): void
    {
        $this->withRequest([]);

        $this->assertSame([], $this->controller->readSubmittedRulesPublic());
    }

    public function testAParameterThatIsNotAListIsNotARuleSet(): void
    {
        $this->withRequest(['rules' => 'bh']);

        $this->assertSame([], $this->controller->readSubmittedRulesPublic());
    }

    public function testARowThatIsNotAListIsSkippedRatherThanGuessedAt(): void
    {
        $this->withRequest(['rules' => ['nonsense', $this->row(['term' => 'bh'])]]);

        $rules = $this->controller->readSubmittedRulesPublic();

        $this->assertCount(1, $rules);
        $this->assertSame('bh', $rules[0]['term']);
    }

    /**
     * OXID escapes parameters into HTML entities on the way in. Left alone, an
     * apostrophe would be stored as `&#039;` and shown back that way on every
     * later visit - the damage is permanent, because the next save stores the
     * escaped form again.
     */
    public function testEntitiesFromTheRequestAreDecodedBeforeTheyReachTheTable(): void
    {
        $this->withRequest(['rules' => [$this->row([
            'term' => 'l&#039;eau',
            'synonyms' => 'eau &amp; wasser',
        ])]]);

        $rules = $this->controller->readSubmittedRulesPublic();

        $this->assertSame("l'eau", $rules[0]['term']);
        $this->assertSame('eau & wasser', $rules[0]['synonyms']);
    }

    public function testSurroundingWhitespaceIsNotPartOfATerm(): void
    {
        $this->withRequest(['rules' => [$this->row(['term' => "  bh \n"])]]);

        $this->assertSame('bh', $this->controller->readSubmittedRulesPublic()[0]['term']);
    }

    public function testAFieldTheFormDidNotPostReadsAsEmpty(): void
    {
        $this->withRequest(['rules' => [['term' => 'bh']]]);

        $rules = $this->controller->readSubmittedRulesPublic();

        $this->assertSame('', $rules[0]['type']);
        $this->assertSame('', $rules[0]['synonyms']);
    }

    /**
     * An unchecked checkbox posts nothing at all, so the row carries a hidden
     * companion field - and only the literal "1" means checked.
     */
    public function testOnlyAnExplicitOneCountsAsActive(): void
    {
        $this->withRequest(['rules' => [
            $this->row(['active' => '1']),
            $this->row(['active' => '0']),
            ['term' => 'x'],
        ]]);

        $rules = $this->controller->readSubmittedRulesPublic();

        $this->assertTrue($rules[0]['active']);
        $this->assertFalse($rules[1]['active']);
        $this->assertFalse($rules[2]['active']);
    }

    /**
     * Request values are `mixed`, so a checkbox that arrives as the number 1
     * rather than the string "1" still means checked - and a field that
     * arrives as a number is still text.
     */
    public function testAValueThatIsNotAStringIsStillReadAsOne(): void
    {
        $this->withRequest(['rules' => [$this->row(['term' => 2024, 'active' => 1])]]);

        $rules = $this->controller->readSubmittedRulesPublic();

        $this->assertSame('2024', $rules[0]['term']);
        $this->assertTrue($rules[0]['active']);
    }

    /**
     * The browser serialises fields in document order, so the posted order is
     * the on-screen order - and that is the order the merchant arranged.
     */
    public function testTheOrderTheFormPostedIsKept(): void
    {
        $this->withRequest(['rules' => [
            $this->row(['term' => 'first']),
            $this->row(['term' => 'second']),
            $this->row(['term' => 'third']),
        ]]);

        $this->assertSame(
            ['first', 'second', 'third'],
            array_column($this->controller->readSubmittedRulesPublic(), 'term')
        );
    }

    // ---------------------------------------------------------------
    // the list the screen renders
    // ---------------------------------------------------------------

    public function testTheConfiguredRulesOfTheScopeAreShown(): void
    {
        $this->controller->currentShopId = 2;
        $this->configured['2_1'] = [new SynonymRule('oneway', 'bralette', 'triangel', false)];
        $this->withRequest(['synonymLang' => '1']);

        $rules = $this->controller->getRules();

        $this->assertSame(
            ['type' => 'oneway', 'term' => 'bralette', 'synonyms' => 'triangel', 'active' => false],
            $rules[0]
        );
    }

    /**
     * Somewhere to type, without having to find an "add" button first.
     */
    public function testAnEmptyScreenStillOffersRowsToTypeInto(): void
    {
        $rules = $this->controller->getRules();

        $this->assertCount(3, $rules);
        $this->assertSame(['', '', ''], array_column($rules, 'term'));
    }

    public function testTheBlankRowsComeAfterTheConfiguredOnes(): void
    {
        $this->configured['1_0'] = [new SynonymRule('both', 'bh', 'bustier')];

        $rules = $this->controller->getRules();

        $this->assertCount(4, $rules);
        $this->assertSame('bh', $rules[0]['term']);
    }

    public function testABlankRowIsActiveAndTwoWayByDefault(): void
    {
        $rules = $this->controller->getRules();

        $this->assertSame(SynonymRule::TYPE_BOTH, $rules[0]['type']);
        $this->assertTrue($rules[0]['active']);
    }

    /**
     * The merchant came here from the report screen to write a rule for
     * exactly that word; typing it a second time is a step nobody needs.
     */
    public function testATermHandedOverFromTheReportArrivesPreFilledAndReadyToUse(): void
    {
        $this->withRequest(['synonymTerm' => 'wintermantel']);

        $rules = $this->controller->getRules();

        $this->assertSame(
            ['type' => SynonymRule::TYPE_BOTH, 'term' => 'wintermantel', 'synonyms' => '', 'active' => true],
            $rules[0]
        );
    }

    public function testTheHandedOverTermTakesOneOfTheBlankRows(): void
    {
        $this->withRequest(['synonymTerm' => 'wintermantel']);

        $this->assertSame(['wintermantel', '', ''], array_column($this->controller->getRules(), 'term'));
    }

    /**
     * A word that already has a rule needs no second row - the merchant should
     * be editing the rule that exists, not writing a duplicate beside it.
     *
     * The count alone does not show this: an added row costs a blank one, so
     * both outcomes have four rows. What differs is whether the word appears
     * twice.
     */
    public function testATermThatAlreadyHasARuleIsNotAddedAgain(): void
    {
        $this->configured['1_0'] = [new SynonymRule('both', 'wintermantel', 'parka')];
        $this->withRequest(['synonymTerm' => 'wintermantel']);

        $this->assertSame(
            ['wintermantel', '', '', ''],
            array_column($this->controller->getRules(), 'term')
        );
    }

    public function testAnExistingRuleIsRecognisedWhateverItsCase(): void
    {
        $this->configured['1_0'] = [new SynonymRule('both', 'Wintermantel', 'parka')];
        $this->withRequest(['synonymTerm' => 'WINTERMANTEL']);

        $this->assertSame(
            ['Wintermantel', '', '', ''],
            array_column($this->controller->getRules(), 'term')
        );
    }

    /**
     * Umlauts are the normal case in a German catalogue, and lowercasing them
     * is not something the byte-wise functions do.
     */
    public function testAnExistingRuleIsRecognisedThroughItsUmlauts(): void
    {
        $this->configured['1_0'] = [new SynonymRule('both', 'GRÖSSE', 'weite')];
        $this->withRequest(['synonymTerm' => 'grösse']);

        $this->assertSame(
            ['GRÖSSE', '', '', ''],
            array_column($this->controller->getRules(), 'term')
        );
    }

    // ---------------------------------------------------------------
    // the term the report screen sends over
    // ---------------------------------------------------------------

    public function testAHandedOverTermIsDecodedAndTrimmed(): void
    {
        $this->withRequest(['synonymTerm' => '  l&#039;eau  ']);

        $this->assertSame("l'eau", $this->controller->getHandedOverTerm());
    }

    /**
     * Cut to the length of the column it would be stored in - in characters,
     * which is what the column counts. Cutting bytes instead would both lose
     * half the allowance on an umlauted term and leave a broken character at
     * the end of it.
     */
    public function testAHandedOverTermIsCutToTheColumnItWouldBeStoredIn(): void
    {
        $this->withRequest(['synonymTerm' => str_repeat('ä', 400)]);

        $this->assertSame(255, mb_strlen($this->controller->getHandedOverTerm()));
    }

    public function testNoTermWasHandedOverWhenNoneWasSent(): void
    {
        $this->assertSame('', $this->controller->getHandedOverTerm());
    }

    public function testAnArrayIsNotATermHandedOver(): void
    {
        $this->withRequest(['synonymTerm' => ['wintermantel']]);

        $this->assertSame('', $this->controller->getHandedOverTerm());
    }

    // ---------------------------------------------------------------
    // which scope the screen is editing
    // ---------------------------------------------------------------

    /**
     * Its own parameter rather than the admin's language switch: that one
     * decides which language the backend is displayed in, which is a different
     * question from which language's synonyms are being edited.
     */
    public function testTheEditedLanguageComesFromItsOwnParameter(): void
    {
        $this->withRequest(['synonymLang' => '2']);

        $this->assertSame(2, $this->controller->getEditLanguageId());
    }

    public function testTheFirstLanguageIsEditedWhenNoneWasChosen(): void
    {
        $this->assertSame(0, $this->controller->getEditLanguageId());
        $this->withRequest(['synonymLang' => '']);
        $this->assertSame(0, $this->controller->getEditLanguageId());
    }

    public function testANegativeLanguageIsNotALanguage(): void
    {
        $this->withRequest(['synonymLang' => '-3']);

        $this->assertSame(0, $this->controller->getEditLanguageId());
    }

    /**
     * `?synonymLang[]=x` used to cast to the integer 1 with no warning, which
     * would have saved one language's rules over another's.
     */
    public function testAnArrayLanguageIsNotSilentlyLanguageOne(): void
    {
        $this->withRequest(['synonymLang' => ['1']]);

        $this->assertSame(0, $this->controller->getEditLanguageId());
    }

    public function testTheEditedShopIsTheOneInContext(): void
    {
        $this->controller->currentShopId = 4;

        $this->assertSame(4, $this->controller->getEditShopId());
    }

    // ---------------------------------------------------------------
    // what the screen offers
    // ---------------------------------------------------------------

    public function testBothRuleTypesAreOfferedWithTheirOwnLabels(): void
    {
        $types = $this->controller->getTypes();

        $this->assertSame(
            [SynonymRule::TYPE_BOTH, SynonymRule::TYPE_ONEWAY],
            array_column($types, 'value')
        );
        $this->assertSame(
            ['FOUN10_EASYSEARCH_SYNONYM_TYPE_BOTH', 'FOUN10_EASYSEARCH_SYNONYM_TYPE_ONEWAY'],
            array_column($types, 'label')
        );
    }

    public function testTheLanguageSwitchOffersTheEditedShopsActiveLanguages(): void
    {
        $this->controller->currentShopId = 2;
        $this->languages = [
            1 => [['id' => 0, 'abbr' => 'de', 'name' => 'Deutsch']],
            2 => [
                ['id' => 0, 'abbr' => 'de', 'name' => 'Deutsch'],
                ['id' => 1, 'abbr' => 'en', 'name' => 'English'],
            ],
        ];

        $this->assertSame(
            [['id' => 0, 'label' => 'Deutsch'], ['id' => 1, 'label' => 'English']],
            $this->controller->getLanguages()
        );
    }

    // ---------------------------------------------------------------
    // the confirmation after a save
    // ---------------------------------------------------------------

    public function testNothingIsConfirmedWhenNothingWasSaved(): void
    {
        $this->assertNull($this->controller->getSavedCount());
    }

    /**
     * Read once and cleared: a confirmation that survived into the next visit
     * would claim a save that did not happen.
     */
    public function testAConfirmationIsShownOnceAndThenForgotten(): void
    {
        $this->storedCount = 4;
        $this->withRequest(['rules' => [$this->row()]]);
        $this->controller->save();

        $this->assertSame(4, $this->controller->getSavedCount());
        $this->assertNull($this->controller->getSavedCount());
    }

    public function testAConfirmationOfNoRulesIsStillAConfirmation(): void
    {
        $this->storedCount = 0;
        $this->withRequest(['rules' => []]);
        $this->controller->save();

        $this->assertSame(0, $this->controller->getSavedCount());
    }

    /**
     * A session round trip is a serialisation, and what comes back is not
     * guaranteed to be the type that went in.
     */
    public function testACountThatCameBackFromTheSessionAsTextIsStillANumber(): void
    {
        $this->controller->session['foun10EasySearchSynonymSaved'] = '12';

        $this->assertSame(12, $this->controller->getSavedCount());
    }
}
