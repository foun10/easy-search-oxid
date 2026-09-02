<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Controller;

use foun10\EasySearch\Controller\SuggestController;
use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Engine\Result\SuggestResult;
use foun10\EasySearch\Engine\SearchEngineInterface;
use foun10\EasySearch\Tests\Unit\Double\SpySearchEngine;
use foun10\EasySearch\Tests\Unit\Double\TestableSuggestController;
use PHPUnit\Framework\TestCase;

/**
 * The suggest endpoint.
 *
 * The dropdown under the search box. It is called on nearly every keystroke, by
 * everyone, before anybody has decided to search for anything - which is what
 * makes its edge cases the normal case rather than the exception. An empty box,
 * a single space, a paste of half a page: all of those arrive here, and none of
 * them may reach the engine as a query.
 *
 * What the tests below do **not** cover is turning IDs into products and
 * categories. That step loads OXID models and reads the shop's own pricing,
 * visibility and link building, so it is stood in for here and tested in the
 * integration suite instead. The price *formatting* is on this side of that
 * line, because it takes a number rather than a Price object - it mirrors the
 * shop's own format_price and getting the currency's sign on the wrong side is
 * exactly the kind of thing nobody notices until a customer does.
 */
class SuggestControllerTest extends TestCase
{
    private TestableSuggestController $controller;

    private SpySearchEngine $engine;

    private int $termLimit = 6;

    private int $productLimit = 6;

    protected function setUp(): void
    {
        $this->engine = new SpySearchEngine();

        $moduleSettings = $this->createMock(ModuleSettings::class);
        $moduleSettings->method('getSuggestTermLimit')->willReturnCallback(fn (): int => $this->termLimit);
        $moduleSettings->method('getSuggestProductLimit')->willReturnCallback(fn (): int => $this->productLimit);

        $this->controller = new TestableSuggestController();
        $this->controller->services = [
            SearchEngineInterface::class => $this->engine,
            ModuleSettings::class => $moduleSettings,
        ];
    }

    private function withTerm(mixed $term): void
    {
        $this->controller->request->raw = [SuggestController::PARAM_TERM => $term];
    }

    /**
     * @return array<string, mixed>
     */
    private function respond(): array
    {
        $this->controller->render();

        return $this->controller->payload();
    }

    // ---------------------------------------------------------------
    // the response itself
    // ---------------------------------------------------------------

    public function testTheAnswerIsJsonAndBelongsToOneCustomerOnly(): void
    {
        $this->withTerm('hem');

        $this->respond();

        $this->assertSame(
            [
                'Content-Type: application/json; charset=utf-8',
                'X-Robots-Tag: noindex',
                'Cache-Control: private, no-store',
            ],
            $this->controller->headers
        );
    }

    /**
     * Product titles go straight into the dropdown's markup, and a URL full of
     * escaped slashes is a URL nobody can read in a log.
     */
    public function testTitlesAndUrlsAreNotEscapedIntoNumericSequences(): void
    {
        $this->engine->suggestResult = new SuggestResult(['größe 38'], [], [], [], 0);
        $this->withTerm('grö');

        $this->controller->render();

        $body = (string) $this->controller->body;
        $this->assertStringContainsString('größe 38', $body);
        $this->assertStringContainsString('https://shop.example/index.php', $body);
    }

    // ---------------------------------------------------------------
    // what counts as something to suggest for
    // ---------------------------------------------------------------

    /**
     * The box is empty far more often than it is not, and an empty term is not
     * a search - asking the engine for one would be a query per keystroke of
     * nothing.
     */
    public function testAnEmptyBoxIsAnsweredWithoutAskingTheEngine(): void
    {
        $this->withTerm('');

        $this->assertSame($this->emptyPayload(), $this->respond());
        $this->assertSame([], $this->engine->suggests);
    }

    public function testAParameterThatWasNeverSentIsAnEmptyBox(): void
    {
        $this->assertSame($this->emptyPayload(), $this->respond());
        $this->assertSame([], $this->engine->suggests);
    }

    public function testABoxHoldingOnlySpacesIsAnEmptyBox(): void
    {
        $this->withTerm("  \n ");

        $this->assertSame($this->emptyPayload(), $this->respond());
        $this->assertSame([], $this->engine->suggests);
    }

    /**
     * `?term[]=x` would warn on the way into a cast and then suggest for the
     * literal string "Array".
     */
    public function testAnArrayIsNotATerm(): void
    {
        $this->withTerm(['hemd']);

        $this->assertSame($this->emptyPayload(), $this->respond());
        $this->assertSame([], $this->engine->suggests);
    }

    public function testATermIsTrimmedBeforeItIsUsed(): void
    {
        $this->withTerm('  hemd  ');

        $this->respond();

        $this->assertSame('hemd', $this->engine->suggests[0]->getTerm());
    }

    /**
     * A paste of half a page is a term as far as the box is concerned, and it
     * must not reach the engine at that length.
     */
    public function testAPathologicallyLongTermIsCutBeforeItReachesTheEngine(): void
    {
        $this->withTerm(str_repeat('a', 500));

        $this->respond();

        $this->assertSame(128, mb_strlen($this->engine->suggests[0]->getTerm()));
    }

    /**
     * Cut by characters rather than bytes, or an umlauted paste loses half its
     * allowance and ends on a broken character.
     */
    public function testTheLengthLimitCountsCharactersRatherThanBytes(): void
    {
        $this->withTerm(str_repeat('ä', 500));

        $this->respond();

        $this->assertSame(str_repeat('ä', 128), $this->engine->suggests[0]->getTerm());
    }

    // ---------------------------------------------------------------
    // what the engine is asked
    // ---------------------------------------------------------------

    public function testTheEngineIsAskedForTheScopeTheCustomerIsIn(): void
    {
        $this->controller->currentShopId = 2;
        $this->controller->currentLanguageId = 1;
        $this->withTerm('hemd');

        $this->respond();

        $this->assertSame(2, $this->engine->suggests[0]->getShopId());
        $this->assertSame(1, $this->engine->suggests[0]->getLangId());
    }

    /**
     * How many of each the dropdown holds is a shop setting, not a constant -
     * a box that shows six products on a wide theme shows fewer on a narrow
     * one.
     */
    public function testTheLimitsComeFromTheShopSettings(): void
    {
        $this->termLimit = 4;
        $this->productLimit = 8;
        $this->withTerm('hemd');

        $this->respond();

        $this->assertSame(4, $this->engine->suggests[0]->getTermLimit());
        $this->assertSame(8, $this->engine->suggests[0]->getProductLimit());
    }

    /**
     * A shop whose index has never been built has nothing to suggest, and
     * asking anyway would be a query against a table that is not there - on
     * every keystroke.
     */
    public function testAShopWithNoIndexIsAnsweredEmptyWithoutBeingAsked(): void
    {
        $this->engine->available = false;
        $this->withTerm('hemd');

        $this->assertSame($this->emptyPayload(), $this->respond());
        $this->assertSame([], $this->engine->suggests);
    }

    // ---------------------------------------------------------------
    // what the dropdown is told
    // ---------------------------------------------------------------

    public function testTheDropdownGetsTermsProductsCategoriesAndATotal(): void
    {
        $this->engine->suggestResult = new SuggestResult(
            ['hemd', 'hemden'],
            ['a-1', 'a-2'],
            ['cat-1'],
            [],
            42
        );
        $this->withTerm('hem');

        $payload = $this->respond();

        $this->assertSame(['hemd', 'hemden'], $payload['terms']);
        $this->assertSame([['id' => 'a-1'], ['id' => 'a-2']], $payload['products']);
        $this->assertSame([['id' => 'cat-1']], $payload['categories']);
        $this->assertSame(42, $payload['total']);
    }

    /**
     * The terms are reindexed, because a JSON object where the dropdown
     * expects an array is a different shape to iterate.
     */
    public function testTheTermsAreAListRatherThanAMap(): void
    {
        $this->engine->suggestResult = new SuggestResult([3 => 'hemd', 7 => 'hemden'], [], [], [], 0);
        $this->withTerm('hem');

        $this->controller->render();

        $this->assertStringContainsString('"terms":["hemd","hemden"]', (string) $this->controller->body);
    }

    public function testTheProductAndCategoryIdsAreHandedOnAsTheEngineRankedThem(): void
    {
        $this->engine->suggestResult = new SuggestResult([], ['a-3', 'a-1'], ['cat-2'], [], 0);
        $this->withTerm('hem');

        $this->respond();

        $this->assertSame([['a-3', 'a-1']], $this->controller->renderedProducts);
        $this->assertSame([['cat-2']], $this->controller->renderedCategories);
    }

    /**
     * The link behind "show all N results" is the same URL the form would
     * submit, so the dropdown and the form cannot disagree about where the
     * search goes.
     */
    public function testTheAllResultsLinkIsTheUrlTheFormWouldSubmit(): void
    {
        $this->withTerm('winter jacke');

        $this->assertSame(
            'https://shop.example/index.php?cl=search&searchparam=winter%20jacke',
            $this->respond()['allUrl']
        );
    }

    public function testTheAllResultsLinkEscapesWhatWouldOtherwiseBreakTheUrl(): void
    {
        $this->withTerm('a&b=c');

        $this->assertStringEndsWith('searchparam=a%26b%3Dc', $this->respond()['allUrl']);
    }

    // ---------------------------------------------------------------
    // when the engine cannot answer
    // ---------------------------------------------------------------

    /**
     * The search box still works without the dropdown, so a failure here is a
     * dropdown that stays closed - never an error under the customer's cursor.
     */
    public function testAFailedRequestDegradesToAnEmptyDropdown(): void
    {
        $this->engine->failing = true;
        $this->withTerm('hemd');

        $this->assertSame($this->emptyPayload(), $this->respond());
    }

    public function testAFailedRequestIsLoggedRatherThanSwallowedSilently(): void
    {
        $this->engine->failing = true;
        $this->withTerm('hemd');

        $this->respond();

        $this->assertSame(
            ['foun10EasySearch: suggest failed - the engine is not answering'],
            $this->controller->loggedErrors
        );
    }

    public function testAFailedRequestStillAnswersWithTheSameHeaders(): void
    {
        $this->engine->failing = true;
        $this->withTerm('hemd');

        $this->respond();

        $this->assertContains('Cache-Control: private, no-store', $this->controller->headers);
    }

    public function testAMissingServiceIsHandledLikeAnyOtherFailure(): void
    {
        $this->controller->services = [];
        $this->withTerm('hemd');

        $this->assertSame($this->emptyPayload(), $this->respond());
        $this->assertCount(1, $this->controller->loggedErrors);
    }

    // ---------------------------------------------------------------
    // prices, as the rest of the shop writes them
    // ---------------------------------------------------------------

    public function testAPriceIsWrittenWithTheCurrencysOwnSeparatorsAndSign(): void
    {
        $this->assertSame('1.234,50 €', $this->controller->formatPricePublic(1234.5));
    }

    /**
     * "Front" means in front and without a space, which is what the shop's own
     * format_price does.
     */
    public function testACurrencyThatBelongsInFrontIsWrittenWithoutASpace(): void
    {
        $this->controller->currency->side = 'Front';
        $this->controller->currency->sign = '$';

        $this->assertSame('$1.234,50', $this->controller->formatPricePublic(1234.5));
    }

    public function testACurrencyWithNoSignIsJustTheNumber(): void
    {
        $this->controller->currency->sign = '';

        $this->assertSame('1.234,50', $this->controller->formatPricePublic(1234.5));
    }

    public function testTheCurrencysDecimalsAreHonoured(): void
    {
        $this->controller->currency->decimal = 0;

        $this->assertSame('1.235 €', $this->controller->formatPricePublic(1234.5));
    }

    /**
     * The currency comes out of the shop's configuration, where numbers are
     * stored as text - so the decimal count arrives as "2", not as 2.
     */
    public function testADecimalCountThatArrivesAsTextIsStillANumber(): void
    {
        $this->controller->currency->decimal = '3';

        $this->assertSame('1.234,500 €', $this->controller->formatPricePublic(1234.5));
    }

    /**
     * Not every currency writes a German number. A shop selling in pounds
     * separates the other way round, and both separators come from the
     * currency rather than from here.
     */
    public function testACurrencyThatSeparatesTheOtherWayRoundIsWrittenItsOwnWay(): void
    {
        $this->controller->currency->sign = '£';
        $this->controller->currency->side = 'Front';
        $this->controller->currency->dec = '.';
        $this->controller->currency->thousand = ',';

        $this->assertSame('£1,234.50', $this->controller->formatPricePublic(1234.5));
    }

    public function testACurrencyThatSaysNothingGetsTheUsualGermanShape(): void
    {
        $this->controller->currency = new \stdClass();

        $this->assertSame('1.234,50', $this->controller->formatPricePublic(1234.5));
    }

    /**
     * An article with no price at all - one whose price is hidden by the
     * shop's own rules - shows nothing rather than "0,00 €".
     */
    public function testAnArticleWithNoPriceShowsNothing(): void
    {
        $this->assertSame('', $this->controller->formatPricePublic(null));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        return ['terms' => [], 'products' => [], 'categories' => [], 'total' => 0, 'allUrl' => ''];
    }
}
