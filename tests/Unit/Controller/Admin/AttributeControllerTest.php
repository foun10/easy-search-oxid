<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Controller\Admin;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\FacetDisplay;
use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Tests\Unit\Double\TestableAttributeController;
use PHPUnit\Framework\TestCase;

/**
 * The attribute screen.
 *
 * The screen that decides what the shop's sidebar filters are, and it says so
 * in an unusual shape: one ordered list, with two independent roles ticked per
 * row. An attribute is very often both a filter and searchable, so they are
 * read as two sets over one order rather than as membership of two lists - and
 * that is what most of the tests below are about, because it is the part that
 * is easy to get subtly wrong.
 *
 * The other half is that the order and the ticks arrive from a drag-and-drop
 * script as comma-separated strings, and the labels arrive as a nested array
 * that OXID has HTML-escaped on the way in. Neither can be trusted to have the
 * shape it should.
 */
class AttributeControllerTest extends TestCase
{
    private TestableAttributeController $controller;

    /** @var array<int, array<string, mixed>> Rows the configuration holds */
    private array $rows = [];

    /** @var array<string, string> Attribute ID => title */
    private array $available = [];

    /** @var array<string, string[]> Attribute ID => example values */
    private array $samples = [];

    /** @var array<int, array<string, string>> Custom labels keyed by language ID */
    private array $customTitles = [];

    /** @var array<int, array{shopId: int, entries: array<int, mixed>}> */
    private array $saves = [];

    /** @var array<int, array{shopId: int, titles: array<string, mixed>}> */
    private array $titleSaves = [];

    /** @var array<int, array{ids: array<int, mixed>, shopId: int, langId: int}> */
    private array $sampleCalls = [];

    /** @var array<int, array<int, array{id: int, abbr: string, name: string}>> Keyed by shop id */
    private array $languages = [1 => [['id' => 0, 'abbr' => 'de', 'name' => 'Deutsch']]];

    protected function setUp(): void
    {
        $configuration = $this->createMock(AttributeConfiguration::class);
        $configuration->method('getRows')->willReturnCallback(fn (int $shopId): array => $this->rows);
        $configuration->method('getAvailableAttributes')->willReturnCallback(
            fn (int $shopId, int $langId): array => $this->available
        );
        $configuration->method('getValueSamples')->willReturnCallback(
            function (array $ids, int $shopId, int $langId): array {
                $this->sampleCalls[] = ['ids' => $ids, 'shopId' => $shopId, 'langId' => $langId];

                return $this->samples;
            }
        );
        $configuration->method('getCustomTitles')->willReturnCallback(
            fn (int $shopId, int $langId): array => $this->customTitles[$langId] ?? []
        );
        $configuration->method('save')->willReturnCallback(
            function (int $shopId, array $entries): void {
                $this->saves[] = ['shopId' => $shopId, 'entries' => $entries];
            }
        );
        $configuration->method('saveTitles')->willReturnCallback(
            function (int $shopId, array $titles): void {
                $this->titleSaves[] = ['shopId' => $shopId, 'titles' => $titles];
            }
        );

        $shopLanguages = $this->createMock(ShopLanguages::class);
        $shopLanguages->method('getActive')->willReturnCallback(
            fn (?int $shopId = null): array => $this->languages[$shopId] ?? []
        );

        $this->controller = new TestableAttributeController();
        $this->controller->services = [
            AttributeConfiguration::class => $configuration,
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
    private function configuredRow(array $fields = []): array
    {
        // Strings throughout, because that is how MySQL hands a row over -
        // a tinyint column arrives as "1", not as 1.
        return $fields + [
            'FOUN10ATTRID' => 'attr-1',
            'FOUN10FACET' => '1',
            'FOUN10EASYSEARCHABLE' => '0',
            'FOUN10DISPLAY' => FacetDisplay::MODE_DEFAULT,
        ];
    }

    // ---------------------------------------------------------------
    // one order, two independent roles
    // ---------------------------------------------------------------

    /**
     * An attribute is very often both a filter and searchable, so the two are
     * read as separate sets over one ordered list. Ticking one must not touch
     * the other.
     */
    public function testTheTwoRolesAreReadIndependentlyOfEachOther(): void
    {
        $this->withRequest([
            'order' => 'a,b,c',
            'facets' => 'a,b',
            'searchable' => 'b,c',
        ]);

        $this->controller->save();

        $this->assertSame(
            [
                ['attributeId' => 'a', 'facet' => true, 'searchable' => false, 'display' => 'default'],
                ['attributeId' => 'b', 'facet' => true, 'searchable' => true, 'display' => 'default'],
                ['attributeId' => 'c', 'facet' => false, 'searchable' => true, 'display' => 'default'],
            ],
            $this->saves[0]['entries']
        );
    }

    /**
     * The order the merchant dragged them into is the order they appear in the
     * sidebar, so it is the order that gets stored.
     */
    public function testTheArrangedOrderIsWhatGetsStored(): void
    {
        $this->withRequest(['order' => 'c,a,b', 'facets' => '', 'searchable' => '']);

        $this->controller->save();

        $this->assertSame(['c', 'a', 'b'], array_column($this->saves[0]['entries'], 'attributeId'));
    }

    /**
     * A tick for an attribute that is not in the list is not a row: the order
     * decides what exists, the ticks only decide what those rows are for.
     */
    public function testATickedAttributeOutsideTheOrderIsNotStored(): void
    {
        $this->withRequest(['order' => 'a', 'facets' => 'a,ghost', 'searchable' => '']);

        $this->controller->save();

        $this->assertSame(['a'], array_column($this->saves[0]['entries'], 'attributeId'));
    }

    public function testAnEmptyListClearsTheArrangement(): void
    {
        $this->withRequest(['order' => '', 'facets' => '', 'searchable' => '']);

        $this->controller->save();

        $this->assertSame([], $this->saves[0]['entries']);
    }

    public function testTheArrangementIsSavedForTheShopBeingEdited(): void
    {
        $this->controller->currentShopId = 3;
        $this->withRequest(['order' => 'a']);

        $this->controller->save();

        $this->assertSame(3, $this->saves[0]['shopId']);
    }

    /**
     * A display mode is a per-attribute setting, and one nobody chose falls
     * back to the plain list rather than to nothing.
     */
    public function testADisplayModeIsTakenPerAttributeAndDefaultedWhenAbsent(): void
    {
        $this->withRequest([
            'order' => 'a,b',
            'display' => ['a' => FacetDisplay::MODE_COLOR],
        ]);

        $this->controller->save();

        $this->assertSame(FacetDisplay::MODE_COLOR, $this->saves[0]['entries'][0]['display']);
        $this->assertSame(FacetDisplay::MODE_DEFAULT, $this->saves[0]['entries'][1]['display']);
    }

    /**
     * A mode for every attribute that has one, not for the first of them.
     */
    public function testEveryChosenDisplayModeIsTaken(): void
    {
        $this->withRequest([
            'order' => 'a,b,c',
            'display' => [
                'a' => FacetDisplay::MODE_COLOR,
                'b' => FacetDisplay::MODE_COLOR,
                'c' => FacetDisplay::MODE_DEFAULT,
            ],
        ]);

        $this->controller->save();

        $this->assertSame(
            [FacetDisplay::MODE_COLOR, FacetDisplay::MODE_COLOR, FacetDisplay::MODE_DEFAULT],
            array_column($this->saves[0]['entries'], 'display')
        );
    }

    public function testADisplayMapThatIsNotAMapIsIgnored(): void
    {
        $this->withRequest(['order' => 'a', 'display' => 'colour']);

        $this->controller->save();

        $this->assertSame(FacetDisplay::MODE_DEFAULT, $this->saves[0]['entries'][0]['display']);
    }

    public function testADisplayModeUnderAKeyThatIsNoAttributeIdIsIgnored(): void
    {
        $this->withRequest([
            'order' => 'a',
            'display' => ['../../etc' => FacetDisplay::MODE_COLOR, 'a' => FacetDisplay::MODE_COLOR],
        ]);

        $this->controller->save();

        $this->assertSame(FacetDisplay::MODE_COLOR, $this->saves[0]['entries'][0]['display']);
    }

    // ---------------------------------------------------------------
    // the lists the drag-and-drop script writes
    // ---------------------------------------------------------------

    public function testACommaSeparatedListBecomesIds(): void
    {
        $this->assertSame(['a', 'b', 'c'], $this->controller->toIdListPublic('a,b,c'));
    }

    public function testWhitespaceAroundAnIdIsNotPartOfIt(): void
    {
        $this->assertSame(['a', 'b'], $this->controller->toIdListPublic(' a , b '));
    }

    /**
     * The script can write the same ID twice - dragging an item onto itself
     * has been enough - and a duplicate row would break the unique key the
     * table has on shop and attribute.
     */
    public function testAnIdListedTwiceIsStoredOnce(): void
    {
        $this->assertSame(['a', 'b'], $this->controller->toIdListPublic('a,b,a'));
    }

    /**
     * @dataProvider notAListProvider
     */
    public function testAnythingThatIsNotAListIsNoIdsAtAll(mixed $value): void
    {
        $this->assertSame([], $this->controller->toIdListPublic($value));
    }

    public function notAListProvider(): array
    {
        return [
            'nothing'      => [null],
            'empty'        => [''],
            'whitespace'   => ['   '],
            'an array'     => [['a', 'b']],
            'a number'     => [42],
        ];
    }

    /**
     * The IDs go into a WHERE clause and into array keys, so anything that is
     * not shaped like an OXID object ID is dropped rather than quoted and
     * hoped for.
     */
    public function testSomethingThatIsNotAnObjectIdIsDropped(): void
    {
        $this->assertSame(
            ['a', 'b'],
            $this->controller->toIdListPublic("a,../../etc/passwd,b,'; DROP TABLE x --")
        );
    }

    public function testAnIdLongerThanTheColumnIsDropped(): void
    {
        $this->assertSame([], $this->controller->toIdListPublic(str_repeat('a', 65)));
    }

    // ---------------------------------------------------------------
    // the labels a merchant types
    // ---------------------------------------------------------------

    /**
     * OXID escapes request parameters into HTML entities on the way in, so an
     * apostrophe would be stored as `&#039;` and shown back that way for good.
     */
    public function testALabelIsDecodedBeforeItIsStored(): void
    {
        $this->withRequest(['title' => ['attr-1' => [0 => 'L&#039;eau &amp; Co']]]);

        $this->assertSame(["L'eau & Co"], array_values($this->controller->readTitlesPublic()['attr-1']));
    }

    public function testALabelIsTrimmed(): void
    {
        $this->withRequest(['title' => ['attr-1' => [0 => "  Farbe \n"]]]);

        $this->assertSame('Farbe', $this->controller->readTitlesPublic()['attr-1'][0]);
    }

    public function testLabelsAreKeptPerLanguage(): void
    {
        $this->withRequest(['title' => ['attr-1' => [0 => 'Farbe', 1 => 'Colour']]]);

        $this->assertSame([0 => 'Farbe', 1 => 'Colour'], $this->controller->readTitlesPublic()['attr-1']);
    }

    public function testATitleParameterThatIsNotAMapIsIgnored(): void
    {
        $this->withRequest(['title' => 'Farbe']);

        $this->assertSame([], $this->controller->readTitlesPublic());
    }

    public function testATitleUnderSomethingThatIsNoAttributeIdIsIgnored(): void
    {
        $this->withRequest(['title' => ['../../etc' => [0 => 'x'], 'attr-1' => [0 => 'Farbe']]]);

        $this->assertSame(['attr-1'], array_keys($this->controller->readTitlesPublic()));
    }

    public function testATitleThatIsNotPerLanguageIsIgnored(): void
    {
        $this->withRequest(['title' => ['attr-1' => 'Farbe']]);

        $this->assertSame([], $this->controller->readTitlesPublic());
    }

    /**
     * Labels are keyed by attribute, so dragging an attribute out of the list
     * drops its labels with it - otherwise they would sit in the table for
     * ever, invisible and still stored.
     */
    public function testALabelForAnAttributeDraggedOutOfTheListIsNotKept(): void
    {
        $this->withRequest([
            'order' => 'a',
            'title' => ['a' => [0 => 'Farbe'], 'b' => [0 => 'Größe']],
        ]);

        $this->controller->save();

        $this->assertSame(['a' => [0 => 'Farbe']], $this->titleSaves[0]['titles']);
    }

    /**
     * Every label the form posted, not the first of them: the screen submits
     * one input per attribute per language, and they all arrive together.
     */
    public function testTheLabelsOfEveryAttributeAreRead(): void
    {
        $this->withRequest(['title' => [
            'a' => [0 => 'Farbe'],
            'b' => [0 => 'Größe'],
            'c' => [0 => 'Material'],
        ]]);

        $this->assertSame(['a', 'b', 'c'], array_keys($this->controller->readTitlesPublic()));
    }

    /**
     * A label arriving as something other than a string - a number typed into
     * the field - is still a label.
     */
    public function testALabelThatIsNotAStringIsStillReadAsOne(): void
    {
        $this->withRequest(['title' => ['attr-1' => ['0' => 2024]]]);

        $this->assertSame([0 => '2024'], $this->controller->readTitlesPublic()['attr-1']);
    }

    public function testTheLabelsAreSavedForTheShopBeingEdited(): void
    {
        $this->controller->currentShopId = 2;
        $this->withRequest(['order' => 'a', 'title' => ['a' => [0 => 'Farbe']]]);

        $this->controller->save();

        $this->assertSame(2, $this->titleSaves[0]['shopId']);
    }

    // ---------------------------------------------------------------
    // the configured list the screen renders
    // ---------------------------------------------------------------

    public function testAConfiguredAttributeCarriesBothRolesAndItsTitle(): void
    {
        $this->available = ['attr-1' => 'Farbe'];
        $this->rows = [$this->configuredRow(['FOUN10FACET' => 1, 'FOUN10EASYSEARCHABLE' => 1])];

        $configured = $this->controller->getConfiguredAttributes();

        $this->assertSame('attr-1', $configured[0]['id']);
        $this->assertSame('Farbe', $configured[0]['title']);
        $this->assertTrue($configured[0]['facet']);
        $this->assertTrue($configured[0]['searchable']);
    }

    /**
     * An attribute deleted from the catalogue keeps its ID as the label, so it
     * can still be seen and dragged out - a row with a blank label is one
     * nobody can identify or remove.
     */
    public function testAnAttributeMissingFromTheCatalogueIsStillIdentifiable(): void
    {
        $this->available = [];
        $this->rows = [$this->configuredRow(['FOUN10ATTRID' => 'gone-1'])];

        $this->assertSame('gone-1', $this->controller->getConfiguredAttributes()[0]['title']);
    }

    public function testARoleIsOnlySetWhenTheColumnSaysExactlyOne(): void
    {
        $this->rows = [
            $this->configuredRow(['FOUN10ATTRID' => 'a', 'FOUN10FACET' => '1']),
            $this->configuredRow(['FOUN10ATTRID' => 'b', 'FOUN10FACET' => '0']),
        ];

        $configured = $this->controller->getConfiguredAttributes();

        $this->assertTrue($configured[0]['facet']);
        $this->assertFalse($configured[1]['facet']);
    }

    /**
     * Every field a row carries is rendered, so the row the screen gets has to
     * be complete - a missing key is a blank column, not an error.
     */
    public function testAConfiguredRowCarriesEverythingTheScreenRenders(): void
    {
        $this->available = ['attr-1' => 'Farbe'];
        $this->samples = ['attr-1' => ['rot', 'blau']];
        $this->customTitles = [0 => ['attr-1' => 'Grundfarbe']];
        $this->rows = [$this->configuredRow()];

        $this->assertSame(
            [
                'id' => 'attr-1',
                'title' => 'Farbe',
                'facet' => true,
                'searchable' => false,
                'display' => FacetDisplay::MODE_DEFAULT,
                'labels' => [0 => 'Grundfarbe'],
                'sample' => 'rot · blau',
            ],
            $this->controller->getConfiguredAttributes()[0]
        );
    }

    /**
     * A mode that was removed from the code while still sitting in a database
     * falls back to the plain list rather than making the facet disappear.
     */
    public function testADisplayModeNoLongerKnownFallsBackToThePlainList(): void
    {
        $this->rows = [$this->configuredRow(['FOUN10DISPLAY' => 'legacycolortile'])];

        $this->assertSame(FacetDisplay::MODE_DEFAULT, $this->controller->getConfiguredAttributes()[0]['display']);
    }

    public function testARowWithNoDisplayColumnStillGetsAMode(): void
    {
        $row = $this->configuredRow();
        unset($row['FOUN10DISPLAY']);
        $this->rows = [$row];

        $this->assertSame(FacetDisplay::MODE_DEFAULT, $this->controller->getConfiguredAttributes()[0]['display']);
    }

    // ---------------------------------------------------------------
    // what is left over
    // ---------------------------------------------------------------

    public function testAnAttributeWithNoRoleIsOfferedAsUnused(): void
    {
        $this->available = ['attr-1' => 'Farbe', 'attr-2' => 'Größe'];
        $this->rows = [$this->configuredRow(['FOUN10ATTRID' => 'attr-1'])];

        $unused = $this->controller->getUnusedAttributes();

        $this->assertSame([['id' => 'attr-2', 'title' => 'Größe', 'sample' => '']], $unused);
    }

    /**
     * All of them, not the first: the list is where a merchant goes looking
     * for the attribute they have not configured yet.
     */
    public function testEveryUnusedAttributeIsOfferedRatherThanTheFirst(): void
    {
        $this->available = ['a' => 'Farbe', 'b' => 'Größe', 'c' => 'Material'];

        $this->assertSame(['a', 'b', 'c'], array_column($this->controller->getUnusedAttributes(), 'id'));
    }

    public function testNothingIsUnusedWhenEverythingIsConfigured(): void
    {
        $this->available = ['attr-1' => 'Farbe'];
        $this->rows = [$this->configuredRow(['FOUN10ATTRID' => 'attr-1'])];

        $this->assertSame([], $this->controller->getUnusedAttributes());
    }

    // ---------------------------------------------------------------
    // the example values that make the screen useful
    // ---------------------------------------------------------------

    /**
     * The question the screen exists to answer is whether an attribute is
     * worth offering as a filter, and the values answer it without making
     * anyone leave for the attribute administration.
     */
    public function testExampleValuesAreShownAsOneReadableLine(): void
    {
        $this->samples = ['attr-1' => ['rot', 'blau', 'grün']];

        $this->assertSame('rot · blau · grün', $this->controller->getSample('attr-1'));
    }

    /**
     * Some attributes hold whole paragraphs - ingredient lists, care
     * instructions. The point is to recognise the kind of value, not to read
     * it.
     */
    /**
     * Kept from the front: the beginning of a care instruction says what kind
     * of value it is, the end of it does not.
     */
    public function testALongValueIsShortenedFromTheFrontRatherThanShownWhole(): void
    {
        $this->samples = ['attr-1' => ['30 Grad Feinwaesche, nicht schleudern, liegend trocknen']];

        $this->assertSame('30 Grad Feinwaesche, nicht schle…', $this->controller->getSample('attr-1'));
    }

    public function testAValueAtTheLimitIsNotShortened(): void
    {
        $this->samples = ['attr-1' => [str_repeat('a', 32)]];

        $this->assertSame(str_repeat('a', 32), $this->controller->getSample('attr-1'));
    }

    /**
     * The limit is in characters, not bytes. Counting bytes would cut an
     * umlauted value at half the allowance - and mark a value as too long
     * that is not.
     */
    public function testTheLimitCountsCharactersRatherThanBytes(): void
    {
        $this->samples = ['attr-1' => [str_repeat('ä', 20)]];

        $this->assertSame(str_repeat('ä', 20), $this->controller->getSample('attr-1'));
    }

    public function testAnUmlautedValueOverTheLimitIsCutByCharactersToo(): void
    {
        $this->samples = ['attr-1' => [str_repeat('ä', 40)]];

        $this->assertSame(str_repeat('ä', 32) . '…', $this->controller->getSample('attr-1'));
    }

    /**
     * Samples are asked for by ID. Handing the whole map over instead would
     * send the titles to a query that binds IDs.
     */
    public function testSamplesAreAskedForByIdForTheShopAndLanguageOnScreen(): void
    {
        $this->controller->currentShopId = 2;
        $this->controller->templateLanguageId = 1;
        $this->available = ['attr-1' => 'Farbe', 'attr-2' => 'Größe'];

        $this->controller->getSample('attr-1');

        $this->assertSame(
            [['ids' => ['attr-1', 'attr-2'], 'shopId' => 2, 'langId' => 1]],
            $this->sampleCalls
        );
    }

    /**
     * Loaded lazily and once - the screen asks for a sample per row, and a
     * query per row is what this avoids.
     */
    public function testTheSamplesAreLoadedOnceHoweverManyRowsAskForThem(): void
    {
        $this->available = ['attr-1' => 'Farbe', 'attr-2' => 'Größe'];

        $this->controller->getSample('attr-1');
        $this->controller->getSample('attr-2');

        $this->assertCount(1, $this->sampleCalls);
    }

    public function testAnAttributeWithNoValuesShowsNothing(): void
    {
        $this->assertSame('', $this->controller->getSample('attr-1'));
    }

    // ---------------------------------------------------------------
    // labels, languages and modes offered to the screen
    // ---------------------------------------------------------------

    public function testALabelIsOfferedForEveryLanguageEvenWhereNoneWasEntered(): void
    {
        $this->languages = [1 => [
            ['id' => 0, 'abbr' => 'de', 'name' => 'Deutsch'],
            ['id' => 1, 'abbr' => 'en', 'name' => 'English'],
        ]];
        $this->customTitles = [0 => ['attr-1' => 'Farbe']];

        $this->assertSame([0 => 'Farbe', 1 => ''], $this->controller->getCustomTitles('attr-1'));
    }

    public function testTheLanguageSwitchShowsTheAbbreviationInCapitals(): void
    {
        $this->languages = [1 => [['id' => 0, 'abbr' => 'de', 'name' => 'Deutsch']]];

        $this->assertSame([['id' => 0, 'label' => 'DE']], $this->controller->getLanguages());
    }

    public function testEveryDisplayModeIsOfferedWithItsOwnLabel(): void
    {
        $modes = $this->controller->getDisplayModes();

        $this->assertSame(FacetDisplay::MODES, array_column($modes, 'value'));
        $this->assertSame(
            array_map(static fn (string $mode): string => FacetDisplay::getLabelIdent($mode), FacetDisplay::MODES),
            array_column($modes, 'label')
        );
    }

    public function testTheEditedShopIsTheOneInContext(): void
    {
        $this->controller->currentShopId = 4;

        $this->assertSame(4, $this->controller->getEditShopId());
    }
}
