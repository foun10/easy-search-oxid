<?php
declare(strict_types=1);

namespace foun10\EasySearch\Extension\Application\Model;

use foun10\EasySearch\Core\ArticleListFactory;
use foun10\EasySearch\Core\RequestQueryFactory;
use foun10\EasySearch\Core\SearchResultProvider;
use foun10\EasySearch\Engine\SearchEngineInterface;
use foun10\EasySearch\Log\SearchLogger;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Throwable;

/**
 * Routes the shop's search through the foun10 engine.
 *
 * Extending the model rather than the controller is deliberate. OXID's
 * SearchController::init() drives everything from this class - article list,
 * total count and page count - so replacing the two methods below leaves
 * pagination, sorting links, breadcrumbs and processListArticles() working
 * exactly as before. Overriding the controller instead would mean either
 * reimplementing that plumbing or running the stock LIKE search alongside ours
 * on every request.
 *
 * If the engine is unavailable - index not built yet, table missing after a
 * fresh deployment - both methods fall back to the shop's own search rather
 * than showing an empty result page.
 */
class Search extends Search_parent
{
    /**
     * @inheritDoc
     */
    public function getSearchArticles(
        $sSearchParamForQuery = false,
        $sInitialSearchCat = false,
        $sInitialSearchVendor = false,
        $sInitialSearchManufacturer = false,
        $sSortBy = false
    ) {
        $engine = $this->foun10GetEngine();

        if ($engine === null) {
            return parent::getSearchArticles(
                $sSearchParamForQuery,
                $sInitialSearchCat,
                $sInitialSearchVendor,
                $sInitialSearchManufacturer,
                $sSortBy
            );
        }

        try {
            $query = $this->foun10GetQueryFactory()->fromRequest(
                is_string($sSortBy) ? $sSortBy : null
            );
            $result = $engine->search($query);
        } catch (Throwable $exception) {
            $this->foun10LogException($exception);

            return parent::getSearchArticles(
                $sSearchParamForQuery,
                $sInitialSearchCat,
                $sInitialSearchVendor,
                $sInitialSearchManufacturer,
                $sSortBy
            );
        }

        // Parked for the controller, which needs the facets and the spelling
        // correction from the very same result.
        $this->foun10GetResultProvider()->set($result);

        // Counted here rather than in the engine: this runs once per search
        // page, where the facet endpoint and the suggest box run the same
        // query again and again.
        $this->foun10GetSearchLogger()?->log($query, $result);

        return $this->foun10GetArticleListFactory()->fromIds($result->getProductIds());
    }

    /**
     * @inheritDoc
     */
    public function getSearchArticleCount(
        $sSearchParamForQuery = false,
        $sInitialSearchCat = false,
        $sInitialSearchVendor = false,
        $sInitialSearchManufacturer = false
    ) {
        $result = $this->foun10GetResultProvider()->get();

        if ($result === null) {
            return parent::getSearchArticleCount(
                $sSearchParamForQuery,
                $sInitialSearchCat,
                $sInitialSearchVendor,
                $sInitialSearchManufacturer
            );
        }

        // The engine counted while searching, so this costs nothing.
        return $result->getTotalCount();
    }

    protected function foun10GetArticleListFactory(): ArticleListFactory
    {
        return ContainerFactory::getInstance()
            ->getContainer()
            ->get(ArticleListFactory::class);
    }

    protected function foun10GetEngine(): ?SearchEngineInterface
    {
        try {
            /** @var SearchEngineInterface $engine */
            $engine = ContainerFactory::getInstance()
                ->getContainer()
                ->get(SearchEngineInterface::class);
        } catch (Throwable $exception) {
            return null;
        }

        $shopId = (int) Registry::getConfig()->getShopId();
        $langId = (int) Registry::getLang()->getBaseLanguage();

        return $engine->isAvailable($shopId, $langId) ? $engine : null;
    }

    protected function foun10GetQueryFactory(): RequestQueryFactory
    {
        return ContainerFactory::getInstance()
            ->getContainer()
            ->get(RequestQueryFactory::class);
    }

    /**
     * Null when the container cannot hand it over - a search must not fail
     * because it could not be counted.
     */
    protected function foun10GetSearchLogger(): ?SearchLogger
    {
        try {
            /** @var SearchLogger $logger */
            $logger = ContainerFactory::getInstance()
                ->getContainer()
                ->get(SearchLogger::class);
        } catch (Throwable $exception) {
            return null;
        }

        return $logger;
    }

    protected function foun10GetResultProvider(): SearchResultProvider
    {
        return ContainerFactory::getInstance()
            ->getContainer()
            ->get(SearchResultProvider::class);
    }

    protected function foun10LogException(Throwable $exception): void
    {
        Registry::getLogger()->error(
            'foun10EasySearch: falling back to stock search - ' . $exception->getMessage(),
            ['exception' => $exception]
        );
    }
}
