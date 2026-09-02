<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Controller\Admin;

use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Index\DictionaryBuilder;
use foun10\EasySearch\Index\MySql\IndexTables;
use foun10\EasySearch\Tests\Unit\Double\TestableIndexController;
use foun10\EasySearch\Tests\Unit\Double\TestableIndexTables;
use PHPUnit\Framework\TestCase;

/**
 * The index screen.
 *
 * A status table and the buttons that rebuild what it shows. Two things decide
 * whether it is any use.
 *
 * It has to survive a shop that has never been indexed - which is every shop on
 * the day the module is installed, and the state in which somebody is most
 * likely to open this screen. There are no tables yet, and a screen that dies
 * on the missing one leaves the merchant with no way to create it.
 *
 * And it has to be honest about scope. The table shows one language and the
 * buttons rebuild one language, so the two have to be the same one - a status
 * for language 0 above buttons that rebuild language 1 is worse than no status.
 */
class IndexControllerTest extends TestCase
{
    private const INDEX = 'foun10easysearchindex_s1';
    private const CATEGORY = 'foun10easysearchindexcategory_s1';
    private const DICTIONARY = 'foun10easysearchdictionary';

    private TestableIndexController $controller;

    /** @var array<int, array<int, array{id: int, abbr: string, name: string}>> Keyed by shop id */
    private array $languages = [1 => [['id' => 0, 'abbr' => 'de', 'name' => 'Deutsch']]];

    protected function setUp(): void
    {
        $shopLanguages = $this->createMock(ShopLanguages::class);
        $shopLanguages->method('getActive')->willReturnCallback(
            fn (?int $shopId = null): array => $this->languages[$shopId] ?? []
        );

        $this->controller = new TestableIndexController();
        $this->controller->services = [
            ShopLanguages::class => $shopLanguages,
            IndexTables::class => new TestableIndexTables(),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function withRequest(array $parameters): void
    {
        $this->controller->request->escaped = $parameters;
    }

    // ---------------------------------------------------------------
    // what the status table says
    // ---------------------------------------------------------------

    public function testTheStatusCountsEachPartOfTheIndexSeparately(): void
    {
        $this->controller->counts = [
            self::INDEX => 338,
            self::CATEGORY => 206,
            self::DICTIONARY => 302,
        ];

        $status = $this->controller->getIndexStatus();

        $this->assertSame(338, $status['documents']);
        $this->assertSame(206, $status['categories']);
        $this->assertSame(302, $status['terms']);
    }

    /**
     * Read from the rows themselves rather than from a settings row: a
     * timestamp somebody has to remember to update is one that will eventually
     * lie.
     */
    public function testEachPartCarriesWhenItWasLastWritten(): void
    {
        $this->controller->timestamps = [
            self::INDEX => '2026-09-02 08:00:00',
            self::CATEGORY => '2026-09-02 08:01:00',
            self::DICTIONARY => '2026-09-02 08:02:00',
        ];

        $status = $this->controller->getIndexStatus();

        $this->assertSame('formatted:2026-09-02 08:00:00', $status['documentsAt']);
        $this->assertSame('formatted:2026-09-02 08:01:00', $status['categoriesAt']);
        $this->assertSame('formatted:2026-09-02 08:02:00', $status['termsAt']);
    }

    public function testTheStatusNamesTheLanguageItIsAbout(): void
    {
        $this->languages = [1 => [['id' => 0, 'abbr' => 'de', 'name' => 'de'], ['id' => 2, 'abbr' => 'fr', 'name' => 'fr']]];
        $this->withRequest(['indexLang' => '2']);

        $this->assertSame(2, $this->controller->getIndexStatus()['langId']);
    }

    public function testEveryCountIsScopedToTheShopAndLanguageOnScreen(): void
    {
        $this->controller->currentShopId = 1;
        $this->languages = [1 => [['id' => 3, 'abbr' => 'it', 'name' => 'it']]];

        $this->controller->getIndexStatus();

        $this->assertSame(
            [':shopId' => 1, ':langId' => 3],
            $this->controller->queryParameters[0]
        );
    }

    public function testTheCountAndTheTimestampComeFromTheSameTable(): void
    {
        $this->controller->getIndexStatus();

        $statements = $this->controller->queriesAgainst(self::INDEX);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString('SELECT COUNT(*) AS VALUE', $statements[0]);
        $this->assertStringContainsString('SELECT MAX(OXTIMESTAMP) AS VALUE', $statements[1]);
        $this->assertStringContainsString('WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId', $statements[0]);
    }

    /**
     * The dictionary is one shared table rather than one per shop, so it is
     * read by its own name and still scoped by the columns.
     */
    public function testTheDictionaryIsReadFromTheSharedTable(): void
    {
        $this->controller->currentShopId = 2;
        $this->languages = [2 => [['id' => 0, 'abbr' => 'de', 'name' => 'de']]];

        $this->controller->getIndexStatus();

        $this->assertCount(2, $this->controller->queriesAgainst(DictionaryBuilder::TABLE));
    }

    // ---------------------------------------------------------------
    // a shop that has never been indexed
    // ---------------------------------------------------------------

    /**
     * The state every shop is in on the day the module is installed, and the
     * one in which somebody is most likely to open this screen. A table that
     * is not there has to read as empty rather than take the screen down -
     * otherwise there is no way left to press the button that creates it.
     */
    public function testAShopWithNoIndexTablesStillGetsAScreen(): void
    {
        $this->controller->missingTables = [self::INDEX, self::CATEGORY, self::DICTIONARY];

        $status = $this->controller->getIndexStatus();

        $this->assertSame(0, $status['documents']);
        $this->assertSame(0, $status['categories']);
        $this->assertSame(0, $status['terms']);
        $this->assertSame('', $status['documentsAt']);
    }

    public function testOneMissingTableDoesNotHideTheOthers(): void
    {
        $this->controller->missingTables = [self::CATEGORY];
        $this->controller->counts = [self::INDEX => 338];

        $status = $this->controller->getIndexStatus();

        $this->assertSame(338, $status['documents']);
        $this->assertSame(0, $status['categories']);
    }

    /**
     * A table that exists but holds nothing for this scope answers MAX() as
     * null, which MySQL hands over as an empty value rather than a date.
     */
    public function testAScopeThatWasNeverWrittenShowsNoDateRatherThanAnEmptyOne(): void
    {
        $this->controller->counts = [self::INDEX => 0];

        $this->assertSame('', $this->controller->getIndexStatus()['documentsAt']);
        $this->assertSame([], $this->controller->formatted);
    }

    /**
     * MySQL's zero date is not a date either, and formatting it produces
     * something that looks like a real - and very old - rebuild.
     */
    public function testTheZeroDateIsNotShownAsARebuild(): void
    {
        $this->controller->timestamps = [self::INDEX => '0000-00-00 00:00:00'];

        $this->assertSame('', $this->controller->getIndexStatus()['documentsAt']);
        $this->assertSame([], $this->controller->formatted);
    }

    // ---------------------------------------------------------------
    // which language the screen is on
    // ---------------------------------------------------------------

    public function testTheChosenLanguageIsShownWhenTheShopServesIt(): void
    {
        $this->languages = [1 => [
            ['id' => 0, 'abbr' => 'de', 'name' => 'de'],
            ['id' => 1, 'abbr' => 'en', 'name' => 'en'],
        ]];
        $this->withRequest(['indexLang' => '1']);

        $this->assertSame(1, $this->controller->getEditLanguageId());
    }

    /**
     * A language the shop does not serve is not a scope, however it got into
     * the URL.
     */
    public function testALanguageTheShopDoesNotServeIsIgnored(): void
    {
        $this->languages = [1 => [['id' => 0, 'abbr' => 'de', 'name' => 'de']]];
        $this->withRequest(['indexLang' => '7']);

        $this->assertSame(0, $this->controller->getEditLanguageId());
    }

    /**
     * Falls back to the first active language rather than to zero: a shop
     * whose only language is not 0 would otherwise open on a scope it does not
     * have, and report an empty index for it.
     */
    public function testAShopWhoseFirstLanguageIsNotZeroOpensOnItsOwn(): void
    {
        $this->languages = [1 => [['id' => 3, 'abbr' => 'it', 'name' => 'it'], ['id' => 4, 'abbr' => 'es', 'name' => 'es']]];

        $this->assertSame(3, $this->controller->getEditLanguageId());
    }

    public function testAShopServingNoLanguageFallsBackToZero(): void
    {
        $this->languages = [];

        $this->assertSame(0, $this->controller->getEditLanguageId());
    }

    public function testAnEmptyLanguageParameterIsNoChoiceAtAll(): void
    {
        $this->languages = [1 => [['id' => 3, 'abbr' => 'it', 'name' => 'it']]];
        $this->withRequest(['indexLang' => '']);

        $this->assertSame(3, $this->controller->getEditLanguageId());
    }

    /**
     * `?indexLang[]=1` used to cast to the integer 1 with no warning, which on
     * a shop serving language 1 would have shown another scope's status under
     * this one's buttons.
     */
    public function testAnArrayLanguageIsNotSilentlyLanguageOne(): void
    {
        $this->languages = [1 => [['id' => 0, 'abbr' => 'de', 'name' => 'de'], ['id' => 1, 'abbr' => 'en', 'name' => 'en']]];
        $this->withRequest(['indexLang' => ['1']]);

        $this->assertSame(0, $this->controller->getEditLanguageId());
    }

    // ---------------------------------------------------------------
    // what the buttons drive
    // ---------------------------------------------------------------

    /**
     * The table shows one language and the buttons rebuild one language, and
     * it has to be the same one.
     */
    public function testTheButtonsRebuildExactlyTheLanguageOnScreen(): void
    {
        $this->languages = [1 => [['id' => 0, 'abbr' => 'de', 'name' => 'de'], ['id' => 2, 'abbr' => 'fr', 'name' => 'fr']]];
        $this->withRequest(['indexLang' => '2']);

        $this->assertSame([['langId' => 2]], $this->controller->getSelectedScope());
        $this->assertSame(2, $this->controller->getIndexStatus()['langId']);
    }

    /**
     * Phases are independent steps rather than a fixed chain, so a button is
     * just a list of them - and "products" clears first, because the rows for
     * the scope have to go before they can be written again.
     */
    public function testEachButtonDrivesItsOwnListOfPhases(): void
    {
        $sets = $this->controller->getPhaseSets();

        $this->assertSame(['clear', 'index', 'category', 'dictionary'], $sets['full']);
        $this->assertSame(['clear', 'index'], $sets['products']);
        $this->assertSame(['category'], $sets['categories']);
        $this->assertSame(['dictionary'], $sets['dictionary']);
    }

    // ---------------------------------------------------------------
    // the language switch
    // ---------------------------------------------------------------

    public function testTheSwitchOffersTheActiveLanguagesOfTheShopInContext(): void
    {
        $this->controller->currentShopId = 2;
        $this->languages = [
            1 => [['id' => 0, 'abbr' => 'de', 'name' => 'Deutsch']],
            2 => [['id' => 0, 'abbr' => 'de', 'name' => 'Deutsch'], ['id' => 1, 'abbr' => 'en', 'name' => 'English']],
        ];

        $this->assertSame(
            [['id' => 0, 'name' => 'Deutsch'], ['id' => 1, 'name' => 'English']],
            $this->controller->getLanguages()
        );
    }

    public function testTheEditedShopIsTheOneInContext(): void
    {
        $this->controller->currentShopId = 4;

        $this->assertSame(4, $this->controller->getEditShopId());
    }
}
