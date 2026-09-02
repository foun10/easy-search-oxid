<?php
declare(strict_types=1);

namespace foun10\EasySearch\Controller;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Core\RequestValues;
use foun10\EasySearch\Engine\Query\SuggestQuery;
use foun10\EasySearch\Engine\Result\SuggestResult;
use foun10\EasySearch\Engine\SearchEngineInterface;
use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\ArticleList;
use OxidEsales\Eshop\Application\Model\Category;
use OxidEsales\Eshop\Core\Price;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Throwable;

/**
 * JSON endpoint behind the search box: index.php?cl=foun10easysearchsuggest&term=...
 *
 * The engine returns IDs; the articles are loaded here so titles, links,
 * pictures and prices come from the shop and honour its visibility and pricing
 * rules.
 *
 * Answers uncached. Two index queries plus loading a handful of articles is
 * cheap at this shop's traffic, and a cache layer would have to be invalidated
 * on every reindex - not worth carrying until the numbers say otherwise.
 *
 * Failures answer with an empty payload rather than an error. A broken suggest
 * box must never stop somebody from submitting the form and reaching the
 * normal result page.
 */
class SuggestController extends FrontendController
{
    use RequestValues;

    public const PARAM_TERM = 'term';

    /**
     * Guards against a pathologically long term reaching the engine.
     */
    protected const MAX_TERM_LENGTH = 128;

    /**
     * Emits JSON and ends the request - no template is involved.
     */
    public function render()
    {
        $payload = $this->buildPayload();

        $this->setHeader('Content-Type: application/json; charset=utf-8');
        $this->setHeader('X-Robots-Tag: noindex');
        // Prices and availability follow the customer's group, so this answer
        // belongs to the customer who asked for it. Said explicitly because a
        // reverse proxy in front of the shop cannot tell that from the URL.
        $this->setHeader('Cache-Control: private, no-store');

        $this->exitWith(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(): array
    {
        $term = trim($this->toString($this->getRequest()->getRequestParameter(self::PARAM_TERM)));
        $term = mb_substr($term, 0, self::MAX_TERM_LENGTH);

        if ($term === '') {
            return $this->getEmptyPayload();
        }

        try {
            return $this->getSuggestions($term);
        } catch (Throwable $exception) {
            $this->logError('foun10EasySearch: suggest failed - ' . $exception->getMessage(), $exception);

            return $this->getEmptyPayload();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSuggestions(string $term): array
    {
        /** @var ModuleSettings $moduleSettings */
        $moduleSettings = $this->getService(ModuleSettings::class);
        /** @var SearchEngineInterface $engine */
        $engine = $this->getService(SearchEngineInterface::class);

        $shopId = $this->getCurrentShopId();
        $langId = $this->getCurrentLanguageId();

        if (!$engine->isAvailable($shopId, $langId)) {
            return $this->getEmptyPayload();
        }

        $query = new SuggestQuery(
            $term,
            $shopId,
            $langId,
            $moduleSettings->getSuggestTermLimit(),
            $moduleSettings->getSuggestProductLimit()
        );

        return $this->renderPayload($engine->suggest($query));
    }

    /**
     * Turns engine IDs into everything the dropdown needs to draw itself.
     *
     * @return array<string, mixed>
     */
    protected function renderPayload(SuggestResult $result): array
    {
        return [
            'terms' => array_values($result->getTerms()),
            'products' => $this->renderProducts($result->getProductIds()),
            'categories' => $this->renderCategories($result->getCategoryIds()),
            'total' => $result->getTotalCount(),
            'allUrl' => $this->getAllResultsUrl(),
        ];
    }

    /**
     * @param string[] $productIds
     *
     * @return array<int, array<string, string>>
     */
    protected function renderProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $articleList = oxNew(ArticleList::class);
        $articleList->loadIds($productIds);

        $products = [];

        // Iterating the engine's IDs rather than the list keeps the relevance
        // order, which loadIds() does not preserve.
        foreach ($productIds as $productId) {
            if (!isset($articleList[$productId])) {
                continue;
            }

            $article = $articleList[$productId];
            $price = $article->getPrice();

            $manufacturer = $article->getManufacturer();

            $products[] = [
                'id' => (string) $productId,
                'title' => (string) $article->getFieldData('oxtitle'),
                'brand' => $manufacturer ? (string) $manufacturer->getFieldData('oxtitle') : '',
                'url' => (string) $article->getLink(),
                'image' => (string) $article->getIconUrl(),
                'price' => $this->formatPrice($price?->getPrice()),
                // Empty unless the article is reduced. The dropdown then draws
                // the same pair as the product box: the old price struck
                // through, the current one in the sale colour.
                'oldPrice' => $this->formatPrice($this->getStrikePrice($article, $price)?->getPrice()),
            ];
        }

        return $products;
    }

    /**
     * Categories as name plus the path that leads to them.
     *
     * The path is what makes a category suggestion usable: "BHs" alone is
     * ambiguous when it exists under both Damen and Herren, so the customer
     * needs to see which one they would land in.
     *
     * @param string[] $categoryIds
     *
     * @return array<int, array<string, string>>
     */
    protected function renderCategories(array $categoryIds): array
    {
        $categories = [];

        foreach ($categoryIds as $categoryId) {
            $category = oxNew(Category::class);

            if (!$category->load($categoryId)) {
                continue;
            }

            $categories[] = [
                'id' => (string) $categoryId,
                'title' => (string) $category->getTitle(),
                'path' => $this->buildCategoryPath($category),
                'url' => (string) $category->getLink(),
            ];
        }

        return $categories;
    }

    /**
     * Ancestors of a category, top down, without the category itself.
     *
     * The guard stops a cyclic parent reference in the data from hanging the
     * request - the same protection the indexer applies.
     */
    protected function buildCategoryPath(Category $category): string
    {
        $titles = [];
        $parent = $category->getParentCategory();
        $guard = 0;

        while ($parent instanceof Category && $guard < 10) {
            $title = trim((string) $parent->getTitle());

            if ($title !== '') {
                array_unshift($titles, $title);
            }

            $parent = $parent->getParentCategory();
            $guard++;
        }

        return implode(' > ', $titles);
    }

    /**
     * Price including the currency sign, formatted the way the rest of the shop
     * formats prices.
     *
     * Mirrors Internal\Transition\Adapter\TemplateLogic\FormatPriceLogic,
     * which is what the format_price twig function uses - same separators, same
     * decimals, and the sign on the side the currency configuration asks for
     * ("Front" means in front and without a space). Reimplemented rather than
     * called because that class lives under Internal and is not public API.
     */
    /**
     * The price to strike through, or null when the article is not reduced.
     *
     * Same test as the product box makes in the listing: a T-price counts as an
     * old price only while it is actually above what the article costs today.
     * Both prices come from the shop's own pricing, so a suggestion cannot
     * promise a discount the product page then does not honour.
     */
    protected function getStrikePrice(Article $article, ?Price $price): ?Price
    {
        $strikePrice = $article->getTPrice();

        if ($price === null || !$strikePrice instanceof Price) {
            return null;
        }

        return $strikePrice->getBruttoPrice() > $price->getBruttoPrice() ? $strikePrice : null;
    }

    protected function formatPrice(?float $price): string
    {
        if ($price === null) {
            return '';
        }

        $currency = $this->getCurrency();
        $sign = (string) ($currency->sign ?? '');

        $formatted = number_format(
            $price,
            isset($currency->decimal) ? (int) $currency->decimal : 2,
            $currency->dec ?? ',',
            $currency->thousand ?? '.'
        );

        if ($sign === '') {
            return $formatted;
        }

        return ($currency->side ?? '') === 'Front'
            ? $sign . $formatted
            : $formatted . ' ' . $sign;
    }

    /**
     * Link behind "show all N results" - the same URL the form would submit.
     */
    protected function getAllResultsUrl(): string
    {
        $term = $this->toString($this->getRequest()->getRequestParameter(self::PARAM_TERM));

        return $this->getShopUrl() . 'index.php?cl=search&searchparam=' . rawurlencode($term);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getEmptyPayload(): array
    {
        return ['terms' => [], 'products' => [], 'categories' => [], 'total' => 0, 'allUrl' => ''];
    }

    /*
     * The shop touch points.
     *
     * renderProducts() and renderCategories() are not among them and are not
     * seams here either: they load OXID models and read the shop's own pricing,
     * visibility and link building, which is the whole of what they do. Faking
     * that would mean testing the fake, so those two - and getStrikePrice()
     * with them - belong to the integration suite. Everything above them does
     * not, which is why formatPrice() takes a float rather than a Price.
     */

    /**
     * @return \OxidEsales\Eshop\Core\Request
     */
    protected function getRequest()
    {
        return Registry::getRequest();
    }

    protected function setHeader(string $header): void
    {
        Registry::getUtils()->setHeader($header);
    }

    protected function exitWith(string $body): void
    {
        Registry::getUtils()->showMessageAndExit($body);
    }

    protected function getCurrentShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    protected function getCurrentLanguageId(): int
    {
        return (int) Registry::getLang()->getBaseLanguage();
    }

    protected function getShopUrl(): string
    {
        return (string) Registry::getConfig()->getShopUrl();
    }

    /**
     * The active currency, whose fields decide how a price reads.
     */
    protected function getCurrency(): object
    {
        return Registry::getConfig()->getActShopCurrencyObject();
    }

    protected function logError(string $message, Throwable $exception): void
    {
        Registry::getLogger()->error($message, ['exception' => $exception]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function getService(string $id): object
    {
        return ContainerFactory::getInstance()->getContainer()->get($id);
    }
}
