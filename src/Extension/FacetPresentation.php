<?php
declare(strict_types=1);

namespace foun10\EasySearch\Extension;

use foun10\EasySearch\Core\RequestQueryFactory;
use foun10\EasySearch\Core\SearchResultProvider;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Engine\Result\SearchResult;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Throwable;

/**
 * Everything the filter sidebar needs, shared by the search page and the
 * category page.
 *
 * The two controllers reach the engine by different routes - the search page
 * through the Search model, the category page through loadArticles() - but once
 * a result exists they present it identically: the same chips, the same toggle
 * links, the same offcanvas panel. Keeping that in one place is what lets both
 * templates include the same partials.
 *
 * A trait rather than a shared base class because each of these classes already
 * has its parent decided for it: OXID generates `SearchController_parent` and
 * `ArticleListController_parent` at runtime, and a module extension has to
 * extend exactly that.
 *
 * The one thing a user of this trait must supply is foun10GetBaseParameters():
 * the parameters every generated link has to carry. They differ - the search
 * page keeps its search term, the category page keeps its category.
 */
trait FacetPresentation
{
    /**
     * Parameters every generated link has to carry, excluding the filters
     * themselves. Paging is deliberately not among them: changing a filter
     * always returns to page one.
     *
     * @return array<string, mixed>
     */
    abstract protected function foun10GetBaseParameters(): array;

    /**
     * Filter groups for the sidebar.
     *
     * @return Facet[]
     */
    public function getFoun10Facets(): array
    {
        $result = $this->foun10GetResult();

        return $result !== null ? $result->getFacets() : [];
    }

    public function hasFoun10Facets(): bool
    {
        return $this->getFoun10Facets() !== [];
    }

    public function hasFoun10ActiveFilters(): bool
    {
        $result = $this->foun10GetResult();

        return $result !== null && $result->hasActiveFilters();
    }

    /**
     * Number of selected values across all facets, for the mobile filter
     * button badge.
     */
    public function getFoun10ActiveFilterCount(): int
    {
        $count = 0;

        foreach ($this->getFoun10Facets() as $facet) {
            $count += count($facet->getSelectedValues());
        }

        return $count;
    }

    /**
     * URL that toggles one facet value on or off, keeping every other filter
     * intact.
     *
     * Built here rather than in Twig because it has to survive SEO URLs, the
     * session ID and the page's own parameters, and because a template has no
     * clean way to add or remove one entry from a nested array.
     */
    public function getFoun10FilterToggleUrl(string $attributeId, string $valueId): string
    {
        $parameters = $this->foun10GetBaseParameters();
        $selected = $parameters[RequestQueryFactory::PARAM_FILTER][$attributeId] ?? [];
        $position = array_search($valueId, $selected, true);

        if ($position === false) {
            $selected[] = $valueId;
        } else {
            unset($selected[$position]);
        }

        $selected = array_values($selected);

        if ($selected === []) {
            unset($parameters[RequestQueryFactory::PARAM_FILTER][$attributeId]);
        } else {
            $parameters[RequestQueryFactory::PARAM_FILTER][$attributeId] = $selected;
        }

        if (($parameters[RequestQueryFactory::PARAM_FILTER] ?? []) === []) {
            unset($parameters[RequestQueryFactory::PARAM_FILTER]);
        }

        return $this->foun10BuildUrl($parameters);
    }

    /**
     * What the facet endpoint needs to reproduce this page.
     *
     * The panel asks index.php?cl=foun10easysearchfacets while the customer is
     * still choosing, and that request has to land on the same result set the
     * page is showing - the search term, or the category, or the manufacturer,
     * plus a price range if one is active. The selection itself is not in here:
     * the panel sends whatever the customer has clicked so far.
     *
     * Read from the page's own base parameters rather than assembled again, so
     * the endpoint and the links underneath it can never disagree about what
     * this page is.
     *
     * @return array<string, string>
     */
    public function getFoun10FacetContext(): array
    {
        $parameters = $this->foun10GetBaseParameters();

        unset($parameters[RequestQueryFactory::PARAM_FILTER], $parameters['cl']);

        $request = Registry::getRequest();

        foreach ([RequestQueryFactory::PARAM_PRICE_FROM, RequestQueryFactory::PARAM_PRICE_TO] as $name) {
            $value = $request->getRequestEscapedParameter($name);

            if (is_numeric($value)) {
                $parameters[$name] = $value;
            }
        }

        $context = [];

        foreach ($parameters as $name => $value) {
            if (is_scalar($value)) {
                $context[(string) $name] = (string) $value;
            }
        }

        return $context;
    }

    /**
     * URL with every filter cleared but the page's own context kept.
     */
    public function getFoun10FilterResetUrl(): string
    {
        $parameters = $this->foun10GetBaseParameters();
        unset($parameters[RequestQueryFactory::PARAM_FILTER]);

        return $this->foun10BuildUrl($parameters);
    }

    /**
     * Keeps the active filters on every link the shop builds for this page.
     *
     * OXID assembles navigation URLs from a fixed whitelist of parameters - cl,
     * cnid, anid, searchparam and a handful more. A module's own parameter is
     * not in that list and is silently dropped, so paging away from a filtered
     * result set landed on the unfiltered one.
     *
     * This one override covers both places it went wrong:
     * generatePageNavigationUrl() builds the pager from getRequestParams(false),
     * and getBaseLink() builds getLink() - which the sorting links extend - from
     * getRequestParams(). getAdditionalParams() is deliberately left alone: the
     * sorting template already appends it on top of getLink(), so filling it in
     * as well would put every filter into those URLs twice.
     *
     * @param bool $addPageNumber
     *
     * @return string
     */
    protected function getRequestParams($addPageNumber = true)
    {
        $parameters = parent::getRequestParams($addPageNumber);
        $filters = $this->foun10GetFilterUrlParams();

        if ($filters === '') {
            return $parameters;
        }

        return $parameters . ($parameters === '' ? '' : '&amp;') . $filters;
    }

    /**
     * The active filters as a URL fragment, in OXID's own &amp; separated style.
     *
     * Read back through RequestQueryFactory rather than off the request
     * directly, so a value that would not survive into a query cannot survive
     * into a link either - one place decides what a valid filter parameter is.
     */
    protected function foun10GetFilterUrlParams(): string
    {
        $factory = $this->foun10GetQueryFactory();

        if ($factory === null) {
            return '';
        }

        $parameters = [];
        $filters = [];

        foreach ($factory->getFilters() as $filter) {
            if ($filter->isEmpty()) {
                continue;
            }

            $filters[$filter->getAttributeId()] = $filter->getValueIds();
        }

        if ($filters !== []) {
            $parameters[RequestQueryFactory::PARAM_FILTER] = $filters;
        }

        $request = Registry::getRequest();

        foreach ([RequestQueryFactory::PARAM_PRICE_FROM, RequestQueryFactory::PARAM_PRICE_TO] as $name) {
            $value = $request->getRequestEscapedParameter($name);

            if (is_numeric($value)) {
                $parameters[$name] = $value;
            }
        }

        if ($parameters === []) {
            return '';
        }

        // The separator is passed explicitly: http_build_query otherwise takes
        // it from arg_separator.output, which a server may well have set to
        // "&amp;" already - and encoding that a second time yields &amp;amp;.
        return str_replace('&', '&amp;', http_build_query($parameters, '', '&'));
    }

    /**
     * Appends the filter parameters to a URL the shop built for itself.
     *
     * Needed wherever a link is assembled without going through
     * getRequestParams() - on a SEO shop the category link is taken straight
     * from the category object, and the whitelist never comes into it.
     */
    protected function foun10AppendFilterParams(string $url): string
    {
        $filters = $this->foun10GetFilterUrlParams();

        if ($filters === '' || $url === '') {
            return $url;
        }

        // Idempotent: several of these hooks feed each other - the pager passes
        // a URL that generatePageNavigationUrl() already completed - and
        // appending twice would send the same filter through as two parameters.
        if (str_contains($url, RequestQueryFactory::PARAM_FILTER)) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&amp;' : '?') . $filters;
    }

    /**
     * The filters currently selected, as the nested array a link is built from.
     *
     * @return array<string, string[]>
     */
    protected function foun10GetSelectedFilters(): array
    {
        $factory = $this->foun10GetQueryFactory();

        if ($factory === null) {
            return [];
        }

        $filters = [];

        foreach ($factory->getFilters() as $filter) {
            if (!$filter->isEmpty()) {
                $filters[$filter->getAttributeId()] = $filter->getValueIds();
            }
        }

        return $filters;
    }

    protected function foun10GetQueryFactory(): ?RequestQueryFactory
    {
        try {
            /** @var RequestQueryFactory $factory */
            $factory = ContainerFactory::getInstance()
                ->getContainer()
                ->get(RequestQueryFactory::class);
        } catch (Throwable $exception) {
            return null;
        }

        return $factory;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function foun10BuildUrl(array $parameters): string
    {
        $config = Registry::getConfig();
        $url = $config->getShopUrl() . 'index.php?' . http_build_query($parameters);

        return Registry::getUtilsUrl()->processUrl($url);
    }

    protected function foun10GetResult(): ?SearchResult
    {
        try {
            /** @var SearchResultProvider $provider */
            $provider = ContainerFactory::getInstance()
                ->getContainer()
                ->get(SearchResultProvider::class);
        } catch (Throwable $exception) {
            return null;
        }

        return $provider->get();
    }
}
