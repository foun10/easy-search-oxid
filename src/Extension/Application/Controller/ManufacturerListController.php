<?php
declare(strict_types=1);

namespace foun10\EasySearch\Extension\Application\Controller;

use foun10\EasySearch\Core\ArticleListFactory;
use foun10\EasySearch\Core\RequestQueryFactory;
use foun10\EasySearch\Core\SearchResultProvider;
use foun10\EasySearch\Engine\SearchEngineInterface;
use foun10\EasySearch\Extension\FacetPresentation;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Throwable;

/**
 * The manufacturer listing, served by the engine so it filters like the
 * category page and the search page.
 *
 * Same shape as the category extension, with two differences worth knowing:
 *
 *  - loadArticles() returns [list, count] here, not just the list. Returning a
 *    bare list would make getArticleList() destructure null and the page would
 *    render empty;
 *  - the manufacturer is a column on the index row rather than a link table.
 *    An article has exactly one manufacturer, so there is nothing to join, and
 *    the filter is a plain equality.
 *
 * Falls back to the shop's own listing whenever the engine cannot serve the
 * scope, exactly as the other two pages do.
 */
class ManufacturerListController extends ManufacturerListController_parent
{
    use FacetPresentation;

    /**
     * @inheritDoc
     */
    protected function loadArticles($oManufacturer)
    {
        $engine = $this->foun10GetEngine();
        $factory = $this->foun10GetQueryFactory();

        if ($engine === null || $factory === null || !is_object($oManufacturer)) {
            return parent::loadArticles($oManufacturer);
        }

        $manufacturerId = (string) $oManufacturer->getId();

        // "root" is the tree node, not a manufacturer with articles.
        if ($manufacturerId === '' || $manufacturerId === 'root') {
            return parent::loadArticles($oManufacturer);
        }

        try {
            $query = $factory->forManufacturer(
                $manufacturerId,
                $this->getSortingSql($this->getSortIdent())
            );
            $result = $engine->search($query);
        } catch (Throwable $exception) {
            Registry::getLogger()->error(
                'foun10EasySearch: manufacturer listing fell back to the shop - ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return parent::loadArticles($oManufacturer);
        }

        // Parked for the template, which needs the facets from this very result.
        $this->foun10GetResultProvider()->set($result);

        $perPage = max(1, $factory->getPageSize());

        $this->_iAllArtCnt = $result->getTotalCount();
        $this->_iCntPages = (int) ceil($this->_iAllArtCnt / $perPage);

        // The parent's contract: getArticleList() destructures both halves.
        return [
            $this->foun10GetArticleListFactory()->fromIds($result->getProductIds()),
            $this->_iAllArtCnt,
        ];
    }

    /**
     * Keeps the filters on the pager.
     *
     * The parent short circuits to the manufacturer's own SEO link when SEO is
     * active, so getRequestParams() is never consulted.
     *
     * @return string
     */
    public function generatePageNavigationUrl()
    {
        return $this->foun10AppendFilterParams((string) parent::generatePageNavigationUrl());
    }

    /**
     * Keeps the filters on the numbered page links, which the parent rebuilds
     * from the manufacturer instead of extending the URL it was handed.
     *
     * @param string   $sUrl
     * @param int      $iPage
     * @param int|null $iLang
     *
     * @return string
     */
    protected function addPageNrParam($sUrl, $iPage, $iLang = null)
    {
        return $this->foun10AppendFilterParams((string) parent::addPageNrParam($sUrl, $iPage, $iLang));
    }

    /**
     * Keeps the filters on the sorting controls and the per-page switch, both
     * of which extend getLink().
     *
     * @param int|null $languageId
     *
     * @return string
     */
    public function getBaseLink($languageId = null)
    {
        return $this->foun10AppendFilterParams((string) parent::getBaseLink($languageId));
    }

    /**
     * Folds the filters into the page cache key.
     *
     * Nothing varies a cached page by our parameter otherwise, and two requests
     * differing only in a filter would be one entry - whichever rendered first
     * being served to both.
     *
     * @return string
     */
    protected function generateViewId()
    {
        $viewId = parent::generateViewId();
        $filters = $this->foun10GetSelectedFilters();

        if ($filters === []) {
            return $viewId;
        }

        ksort($filters);

        foreach ($filters as &$valueIds) {
            sort($valueIds);
        }

        unset($valueIds);

        return $viewId . '|' . md5(serialize($filters));
    }

    /**
     * Parameters every generated link on this page has to carry.
     *
     * @return array<string, mixed>
     */
    protected function foun10GetBaseParameters(): array
    {
        $request = Registry::getRequest();
        $parameters = ['cl' => 'manufacturerlist'];

        $manufacturerId = (string) $request->getRequestEscapedParameter('mnid');

        if ($manufacturerId === '') {
            $manufacturer = $this->getActManufacturer();
            $manufacturerId = is_object($manufacturer) ? (string) $manufacturer->getId() : '';
        }

        if ($manufacturerId !== '') {
            $parameters['mnid'] = $manufacturerId;
        }

        $filters = $this->foun10GetSelectedFilters();

        if ($filters !== []) {
            $parameters[RequestQueryFactory::PARAM_FILTER] = $filters;
        }

        foreach (['listorderby', 'listorder'] as $passThrough) {
            $value = (string) $request->getRequestEscapedParameter($passThrough);

            if ($value !== '') {
                $parameters[$passThrough] = $value;
            }
        }

        return $parameters;
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

    protected function foun10GetArticleListFactory(): ArticleListFactory
    {
        return ContainerFactory::getInstance()
            ->getContainer()
            ->get(ArticleListFactory::class);
    }

    protected function foun10GetResultProvider(): SearchResultProvider
    {
        return ContainerFactory::getInstance()
            ->getContainer()
            ->get(SearchResultProvider::class);
    }
}
