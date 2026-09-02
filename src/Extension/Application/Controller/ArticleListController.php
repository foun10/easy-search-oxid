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
 * Serves the category listing from the foun10 engine, so category pages get the
 * same facets the search page has.
 *
 * Hooked at loadArticles() rather than at the model, the way the search page is:
 * ArticleListController builds its own list and its own counts here, and this is
 * the single point where both are decided. Everything above it - pagination,
 * sorting links, breadcrumbs, the locator - keeps working untouched.
 *
 * One engine-owned path, not a split between filtered and unfiltered. A split
 * would mean every ranking improvement has to be built twice, and the two halves
 * would drift in what they consider "in this category". The safety net is a
 * fallback rather than a second feature: when the engine cannot serve the scope
 * the shop's own listing takes over, exactly as it does on the search page.
 *
 * Deliberately falls back for price categories. Those are defined by a price
 * range rather than by assignment, OXID answers them with loadPriceArticles(),
 * and half-supporting them here would be worse than leaving them alone.
 */
class ArticleListController extends ArticleListController_parent
{
    use FacetPresentation;

    /**
     * @inheritDoc
     */
    protected function loadArticles($category)
    {
        $engine = $this->foun10GetEngine();
        $factory = $this->foun10GetQueryFactory();

        if ($engine === null || $factory === null || !is_object($category) || $category->isPriceCategory()) {
            return parent::loadArticles($category);
        }

        $categoryId = (string) $category->getId();

        if ($categoryId === '') {
            return parent::loadArticles($category);
        }

        try {
            $query = $factory->forCategory(
                $categoryId,
                $this->getSortingSql($this->getSortIdent())
            );
            $result = $engine->search($query);
        } catch (Throwable $exception) {
            Registry::getLogger()->error(
                'foun10EasySearch: category listing fell back to the shop - ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return parent::loadArticles($category);
        }

        // Parked for the template, which needs the facets from this very result.
        $this->foun10GetResultProvider()->set($result);

        $perPage = max(1, $factory->getPageSize());

        // Both are what the parent would have set; the pager reads them.
        $this->_iAllArtCnt = $result->getTotalCount();
        $this->_iCntPages = (int) ceil($this->_iAllArtCnt / $perPage);

        return $this->foun10GetArticleListFactory()->fromIds($result->getProductIds());
    }

    /**
     * Keeps the filters on the pager.
     *
     * The parent short circuits to the category's own SEO link when SEO is
     * active, so getRequestParams() - which is where the search page solves
     * this - is never consulted here.
     *
     * @return string
     */
    public function generatePageNavigationUrl()
    {
        return $this->foun10AppendFilterParams((string) parent::generatePageNavigationUrl());
    }

    /**
     * Keeps the filters on every other link the page builds from itself:
     * the sorting controls and the articles-per-page switch both extend
     * getLink(), which is built from this.
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
     * Keeps the filters on the numbered page links.
     *
     * The parent does not append a page number to the URL it is given: on a SEO
     * shop it discards that URL entirely and rebuilds the link from the
     * category, so everything the pager was carrying is dropped for every page
     * but the first.
     *
     * @param string   $url
     * @param int      $currentPage
     * @param int|null $languageId
     *
     * @return string
     */
    protected function addPageNrParam($url, $currentPage, $languageId = null)
    {
        return $this->foun10AppendFilterParams(
            (string) parent::addPageNrParam($url, $currentPage, $languageId)
        );
    }

    /**
     * Folds the active filters into the page cache key.
     *
     * The EE ArticleListController varies its cache by OXID's own `attrfilter`
     * and by nothing else, so two requests that differ only in a foun10filter
     * are one page as far as the cache is concerned - and whichever rendered
     * first is served to both. That showed up as a filtered result set being
     * handed back for the unfiltered category, which reads as the filter
     * refusing to clear.
     *
     * Serialised and hashed rather than appended raw: the value is a nested
     * array, the view id is a delimited string, and an ID containing the
     * delimiter would quietly merge two different cache entries.
     *
     * @return string
     */
    protected function generateViewId()
    {
        $viewId = parent::generateViewId();
        $filters = $this->foun10GetSelectedFilters();
        $request = Registry::getRequest();

        $prices = [];

        foreach ([RequestQueryFactory::PARAM_PRICE_FROM, RequestQueryFactory::PARAM_PRICE_TO] as $name) {
            $value = $request->getRequestEscapedParameter($name);

            if (is_numeric($value)) {
                $prices[$name] = (string) $value;
            }
        }

        if ($filters === [] && $prices === []) {
            // Nothing selected: the key stays exactly what the shop would have
            // produced, so an unfiltered page keeps sharing its cache entry.
            return $viewId;
        }

        // Sorted, so the same selection reached through a different click order
        // is the same cache entry rather than a second copy of one page.
        ksort($filters);

        foreach ($filters as &$valueIds) {
            sort($valueIds);
        }

        unset($valueIds);
        ksort($prices);

        return $viewId . '|' . md5(serialize($filters) . '|' . serialize($prices));
    }

    /**
     * Parameters every generated link on a category page has to carry.
     *
     * Paging is dropped on purpose - changing a filter always returns to page
     * one - and so is the search term, which has no meaning here.
     *
     * @return array<string, mixed>
     */
    protected function foun10GetBaseParameters(): array
    {
        $request = Registry::getRequest();
        $parameters = ['cl' => 'alist'];

        $categoryId = (string) $request->getRequestEscapedParameter('cnid');

        if ($categoryId === '') {
            $category = $this->getActiveCategory();
            $categoryId = is_object($category) ? (string) $category->getId() : '';
        }

        if ($categoryId !== '') {
            $parameters['cnid'] = $categoryId;
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

    /**
     * The engine, or null when it cannot serve this shop and language - an
     * index that was never built, or a table missing after a fresh deployment.
     */
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
