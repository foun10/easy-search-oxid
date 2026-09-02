<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Controller\SuggestController;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * SuggestController with the request, the container, the currency and the
 * response supplied by the test.
 *
 * Two of the overrides below are not shop touch points but whole steps:
 * renderProducts() and renderCategories() load OXID models and read the shop's
 * pricing, visibility and link building. Standing in for them here is not a
 * seam for the sake of testing - it is the boundary of what this suite can
 * honestly claim. Everything on this side of it is the endpoint's own
 * reasoning: what counts as a term, what the dropdown is told, and what happens
 * when the engine cannot answer.
 */
class TestableSuggestController extends SuggestController
{
    public FakeRequest $request;

    public int $currentShopId = 1;

    public int $currentLanguageId = 0;

    public string $shopUrl = 'https://shop.example/';

    /** @var array<string, object> Container entries, keyed by service id */
    public array $services = [];

    /** @var string[] Headers sent, in order */
    public array $headers = [];

    public ?string $body = null;

    /** @var string[] Messages that went to the log */
    public array $loggedErrors = [];

    /** @var array<int, string[]> The product ID lists handed to the renderer */
    public array $renderedProducts = [];

    /** @var array<int, string[]> The category ID lists handed to the renderer */
    public array $renderedCategories = [];

    /** The active currency, as OXID's config hands it over */
    public stdClass $currency;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(array $parameters = [])
    {
        $this->request = new FakeRequest([], $parameters);

        $this->currency = new stdClass();
        $this->currency->sign = '€';
        $this->currency->decimal = 2;
        $this->currency->dec = ',';
        $this->currency->thousand = '.';
        $this->currency->side = '';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->body === null ? [] : (array) json_decode($this->body, true);
    }

    public function formatPricePublic(?float $price): string
    {
        return $this->formatPrice($price);
    }

    protected function getRequest()
    {
        return $this->request;
    }

    protected function setHeader(string $header): void
    {
        $this->headers[] = $header;
    }

    protected function exitWith(string $body): void
    {
        $this->body = $body;
    }

    protected function getCurrentShopId(): int
    {
        return $this->currentShopId;
    }

    protected function getCurrentLanguageId(): int
    {
        return $this->currentLanguageId;
    }

    protected function getShopUrl(): string
    {
        return $this->shopUrl;
    }

    protected function getCurrency(): object
    {
        return $this->currency;
    }

    protected function logError(string $message, Throwable $exception): void
    {
        $this->loggedErrors[] = $message;
    }

    protected function getService(string $id): object
    {
        return $this->services[$id]
            ?? throw new RuntimeException('no service registered for ' . $id);
    }

    /**
     * @param string[] $productIds
     *
     * @return array<int, array<string, string>>
     */
    protected function renderProducts(array $productIds): array
    {
        $this->renderedProducts[] = $productIds;

        return array_map(static fn (string $id): array => ['id' => $id], $productIds);
    }

    /**
     * @param string[] $categoryIds
     *
     * @return array<int, array<string, string>>
     */
    protected function renderCategories(array $categoryIds): array
    {
        $this->renderedCategories[] = $categoryIds;

        return array_map(static fn (string $id): array => ['id' => $id], $categoryIds);
    }
}
