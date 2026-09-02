<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Controller;

use foun10\EasySearch\Controller\FacetController;
use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Core\RequestQueryFactory;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Engine\Result\FacetValue;
use foun10\EasySearch\Engine\SearchEngineInterface;
use foun10\EasySearch\Tests\Unit\Double\SpySearchEngine;
use foun10\EasySearch\Tests\Unit\Double\TestableFacetController;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The facet endpoint.
 *
 * The JSON behind the filter panel. The panel used to be a set of links, where
 * every click reloaded the whole listing just to find out what was still
 * selectable; this answers that question without the reload.
 *
 * Two properties matter more than the shape of the payload. It must never take
 * the page down - the panel still holds server-rendered links underneath, so a
 * failed request has to degrade to the behaviour customers had before this
 * endpoint existed rather than to an error. And it must not be cached by
 * anything in front of the shop, because prices and availability follow the
 * customer's group and the URL does not say so.
 *
 * The payload itself is deliberately availability rather than counts: the panel
 * strikes through what leads nowhere, and the only number a customer sees is
 * the total on the apply button.
 */
class FacetControllerTest extends TestCase
{
    private TestableFacetController $controller;

    private SpySearchEngine $engine;

    /** @var array<int, array{method: string, argument: string}> */
    private array $factoryCalls = [];

    private int $valueLimit = 20;

    protected function setUp(): void
    {
        $this->engine = new SpySearchEngine();

        $factory = $this->createMock(RequestQueryFactory::class);
        $factory->method('fromRequest')->willReturnCallback(
            function (): SearchQuery {
                $this->factoryCalls[] = ['method' => 'fromRequest', 'argument' => ''];

                return new SearchQuery('hemd', 1, 0);
            }
        );
        $factory->method('forCategory')->willReturnCallback(
            function (string $categoryId): SearchQuery {
                $this->factoryCalls[] = ['method' => 'forCategory', 'argument' => $categoryId];

                return new SearchQuery('', 1, 0, [], SearchQuery::SORT_RELEVANCE, 0, 24, $categoryId);
            }
        );
        $factory->method('forManufacturer')->willReturnCallback(
            function (string $manufacturerId): SearchQuery {
                $this->factoryCalls[] = ['method' => 'forManufacturer', 'argument' => $manufacturerId];

                return new SearchQuery('', 1, 0, [], SearchQuery::SORT_RELEVANCE, 0, 24, null, $manufacturerId);
            }
        );

        $moduleSettings = $this->createMock(ModuleSettings::class);
        $moduleSettings->method('getFacetValueLimit')->willReturnCallback(fn (): int => $this->valueLimit);

        $this->controller = new TestableFacetController();
        $this->controller->services = [
            SearchEngineInterface::class => $this->engine,
            RequestQueryFactory::class => $factory,
            ModuleSettings::class => $moduleSettings,
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
     * @return array<string, mixed>
     */
    private function respond(): array
    {
        $this->controller->render();

        return $this->controller->payload();
    }

    private function facet(string $id, FacetValue ...$values): Facet
    {
        return new Facet($id, ucfirst($id), $values, Facet::TYPE_LIST, 0);
    }

    // ---------------------------------------------------------------
    // the response itself
    // ---------------------------------------------------------------

    public function testTheAnswerIsJson(): void
    {
        $this->respond();

        $this->assertContains('Content-Type: application/json; charset=utf-8', $this->controller->headers);
    }

    /**
     * Prices and availability follow the customer's group, so this answer
     * belongs to the customer who asked for it - and a reverse proxy cannot
     * tell that from the URL.
     */
    public function testTheAnswerIsMarkedAsBelongingToOneCustomerOnly(): void
    {
        $this->respond();

        $this->assertContains('Cache-Control: private, no-store', $this->controller->headers);
    }

    /**
     * A JSON endpoint with a crawlable URL is a page a search engine will
     * happily index.
     */
    public function testTheAnswerIsKeptOutOfSearchEngines(): void
    {
        $this->respond();

        $this->assertContains('X-Robots-Tag: noindex', $this->controller->headers);
    }

    public function testTheHeadersAreSentBeforeTheBody(): void
    {
        $this->respond();

        $this->assertCount(3, $this->controller->headers);
        $this->assertNotNull($this->controller->body);
    }

    /**
     * Slashes and umlauts are left as they are: the payload carries labels and
     * IDs a browser inserts into markup, and escaping them here only makes the
     * response bigger and the labels wrong.
     */
    public function testLabelsAreNotEscapedIntoNumericSequences(): void
    {
        $this->engine->facets = [
            $this->facet('size', new FacetValue('m', 'Größe M/L', 3)),
        ];

        $this->controller->render();

        $this->assertStringContainsString('Größe M/L', (string) $this->controller->body);
    }

    // ---------------------------------------------------------------
    // what the panel is told
    // ---------------------------------------------------------------

    /**
     * The only number a customer sees is the total on the apply button.
     */
    public function testTheTotalIsWhatTheApplyButtonCounts(): void
    {
        $this->engine->totalCount = 1234;

        $this->assertSame(1234, $this->respond()['total']);
    }

    public function testAFacetCarriesWhatThePanelHasToRender(): void
    {
        $this->engine->facets = [
            new Facet('colour', 'Farbe', [new FacetValue('red', 'Rot', 3, true, '#ff0000')], Facet::TYPE_COLOR, 0),
        ];

        $facet = $this->respond()['facets'][0];

        $this->assertSame('colour', $facet['id']);
        $this->assertSame('Farbe', $facet['title']);
        $this->assertSame(Facet::TYPE_COLOR, $facet['type']);
        $this->assertSame(1, $facet['selected']);
    }

    public function testAValueCarriesWhatThePanelHasToRender(): void
    {
        $this->engine->facets = [
            $this->facet('colour', new FacetValue('red', 'Rot', 3, true, '#ff0000')),
        ];

        $this->assertSame(
            ['id' => 'red', 'label' => 'Rot', 'hex' => '#ff0000', 'selected' => true, 'available' => true],
            $this->respond()['facets'][0]['values'][0]
        );
    }

    /**
     * Availability rather than a count: the panel strikes through what leads
     * nowhere. The counts still exist inside the engine - a value at zero is
     * what makes it unavailable - but they stop here.
     */
    public function testAValueThatLeadsNowhereIsMarkedUnavailableWithoutSayingHowMany(): void
    {
        $this->engine->facets = [
            $this->facet('colour', new FacetValue('blue', 'Blau', 0)),
        ];

        $value = $this->respond()['facets'][0]['values'][0];

        $this->assertFalse($value['available']);
        $this->assertArrayNotHasKey('count', $value);
    }

    public function testAValueWithNoColourSaysSoRatherThanOmittingIt(): void
    {
        $this->engine->facets = [$this->facet('size', new FacetValue('m', 'M', 3))];

        $this->assertNull($this->respond()['facets'][0]['values'][0]['hex']);
    }

    public function testEveryFacetIsAnswered(): void
    {
        $this->engine->facets = [
            $this->facet('colour', new FacetValue('red', 'Rot', 3)),
            $this->facet('size', new FacetValue('m', 'M', 2)),
            $this->facet('material', new FacetValue('cotton', 'Baumwolle', 1)),
        ];

        $this->assertSame(
            ['colour', 'size', 'material'],
            array_column($this->respond()['facets'], 'id')
        );
    }

    public function testEveryValueOfAFacetIsAnswered(): void
    {
        $this->engine->facets = [
            $this->facet(
                'colour',
                new FacetValue('red', 'Rot', 3),
                new FacetValue('blue', 'Blau', 2),
                new FacetValue('green', 'Grün', 0)
            ),
        ];

        $this->assertSame(
            ['red', 'blue', 'green'],
            array_column($this->respond()['facets'][0]['values'], 'id')
        );
    }

    /**
     * A value the answer does not mention is one that leads nowhere - unless
     * the list was cut off at the display limit, in which case its absence
     * says nothing and the panel has to leave the rest alone.
     */
    public function testAListCutOffAtTheLimitSaysSo(): void
    {
        $this->valueLimit = 2;
        $this->engine->facets = [
            $this->facet('colour', new FacetValue('red', 'Rot', 3), new FacetValue('blue', 'Blau', 2)),
        ];

        $this->assertTrue($this->respond()['facets'][0]['truncated']);
    }

    public function testAListShorterThanTheLimitIsComplete(): void
    {
        $this->valueLimit = 20;
        $this->engine->facets = [$this->facet('colour', new FacetValue('red', 'Rot', 3))];

        $this->assertFalse($this->respond()['facets'][0]['truncated']);
    }

    /**
     * Deliberately no products: the list behind the panel does not change
     * while the customer is still choosing, and shipping it on every click
     * would be the reload this endpoint exists to avoid.
     */
    public function testNoProductsAreSentAtAll(): void
    {
        $this->engine->productIds = ['a-1', 'a-2'];

        $payload = $this->respond();

        $this->assertSame(['total', 'facets'], array_keys($payload));
    }

    public function testTheEngineIsAskedForOneProductRatherThanAPageOfThem(): void
    {
        $this->respond();

        $this->assertSame(1, $this->engine->searches[0]->getLimit());
    }

    // ---------------------------------------------------------------
    // which page the panel belongs to
    // ---------------------------------------------------------------

    /**
     * Search, category and manufacturer pages are the same request with a
     * different parameter, read exactly as the listing controllers read it -
     * so a filter that would not survive into a listing URL cannot survive
     * into this one either.
     */
    public function testACategoryPageAsksForThatCategory(): void
    {
        $this->withRequest([FacetController::PARAM_CATEGORY => 'cat-1']);

        $this->respond();

        $this->assertSame([['method' => 'forCategory', 'argument' => 'cat-1']], $this->factoryCalls);
    }

    public function testAManufacturerPageAsksForThatManufacturer(): void
    {
        $this->withRequest([FacetController::PARAM_MANUFACTURER => 'man-1']);

        $this->respond();

        $this->assertSame([['method' => 'forManufacturer', 'argument' => 'man-1']], $this->factoryCalls);
    }

    public function testASearchPageAsksForTheRequestAsItStands(): void
    {
        $this->respond();

        $this->assertSame([['method' => 'fromRequest', 'argument' => '']], $this->factoryCalls);
    }

    /**
     * A category wins over a manufacturer, because a page is one or the other
     * and the category parameter is the one the shop's own listing honours
     * first.
     */
    public function testACategoryWinsWhenBothAreNamed(): void
    {
        $this->withRequest([
            FacetController::PARAM_CATEGORY => 'cat-1',
            FacetController::PARAM_MANUFACTURER => 'man-1',
        ]);

        $this->respond();

        $this->assertSame('forCategory', $this->factoryCalls[0]['method']);
    }

    public function testAnEmptyParameterIsNoParameter(): void
    {
        $this->withRequest([FacetController::PARAM_CATEGORY => '   ']);

        $this->respond();

        $this->assertSame('fromRequest', $this->factoryCalls[0]['method']);
    }

    /**
     * `?cnid[]=x` would warn on the way into a cast and then narrow the panel
     * to the literal string "Array".
     */
    public function testAnArrayParameterIsNoParameterEither(): void
    {
        $this->withRequest([FacetController::PARAM_CATEGORY => ['cat-1']]);

        $this->respond();

        $this->assertSame('fromRequest', $this->factoryCalls[0]['method']);
    }

    public function testWhitespaceAroundAnIdIsNotPartOfIt(): void
    {
        $this->withRequest([FacetController::PARAM_CATEGORY => '  cat-1  ']);

        $this->respond();

        $this->assertSame('cat-1', $this->factoryCalls[0]['argument']);
    }

    public function testAManufacturerIdIsTrimmedTheSameWay(): void
    {
        $this->withRequest([FacetController::PARAM_MANUFACTURER => '  man-1  ']);

        $this->respond();

        $this->assertSame(
            [['method' => 'forManufacturer', 'argument' => 'man-1']],
            $this->factoryCalls
        );
    }

    public function testAManufacturerParameterOfOnlyWhitespaceIsNoParameter(): void
    {
        $this->withRequest([FacetController::PARAM_MANUFACTURER => '   ']);

        $this->respond();

        $this->assertSame('fromRequest', $this->factoryCalls[0]['method']);
    }

    // ---------------------------------------------------------------
    // when the module cannot answer
    // ---------------------------------------------------------------

    /**
     * A shop whose index has never been built has no facets to offer, and
     * asking the engine anyway would be a query against a table that is not
     * there.
     */
    public function testAShopWithNoIndexIsAnsweredEmptyWithoutBeingSearched(): void
    {
        $this->engine->available = false;

        $this->assertSame(['total' => 0, 'facets' => []], $this->respond());
        $this->assertSame(0, $this->engine->searchCount());
    }

    public function testAvailabilityIsAskedForTheScopeTheCustomerIsIn(): void
    {
        $this->controller->currentShopId = 2;
        $this->controller->currentLanguageId = 1;
        $this->engine->available = false;

        $this->respond();

        $this->assertSame(0, $this->engine->searchCount());
    }

    /**
     * The panel still holds server-rendered links underneath, so a customer
     * whose request fails keeps the behaviour they had before this endpoint
     * existed - an empty answer, not an error page.
     */
    public function testAFailedRequestDegradesToAnEmptyAnswerRatherThanAnError(): void
    {
        $this->engine->failing = true;

        $this->assertSame(['total' => 0, 'facets' => []], $this->respond());
    }

    public function testAFailedRequestStillAnswersWithTheSameHeaders(): void
    {
        $this->engine->failing = true;

        $this->respond();

        $this->assertContains('Cache-Control: private, no-store', $this->controller->headers);
        $this->assertContains('Content-Type: application/json; charset=utf-8', $this->controller->headers);
    }

    public function testAFailedRequestIsLoggedRatherThanSwallowedSilently(): void
    {
        $this->engine->failing = true;

        $this->respond();

        $this->assertSame(
            ['foun10EasySearch: facet request failed - the engine is not answering'],
            $this->controller->loggedErrors
        );
    }

    /**
     * A container without the engine registered is the state of a shop whose
     * module was half deactivated, and it must not reach the customer as a
     * stack trace either.
     */
    public function testAMissingServiceIsHandledLikeAnyOtherFailure(): void
    {
        $this->controller->services = [];

        $this->assertSame(['total' => 0, 'facets' => []], $this->respond());
        $this->assertCount(1, $this->controller->loggedErrors);
    }

    public function testAnEngineThatThrowsIsNotRetried(): void
    {
        $this->engine->failing = true;

        $this->respond();

        $this->assertSame(1, $this->engine->searchCount());
    }

    public function testTheEndpointNeverThrows(): void
    {
        $this->controller->services = [
            SearchEngineInterface::class => new class () extends SpySearchEngine {
                public function isAvailable(int $shopId, int $langId): bool
                {
                    throw new RuntimeException('the index table is missing');
                }
            },
        ];

        $this->assertSame(['total' => 0, 'facets' => []], $this->respond());
    }
}
