<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use foun10\EasySearch\Engine\Query\FacetFilter;
use foun10\EasySearch\Engine\Query\SearchQuery;
use OxidEsales\Eshop\Core\Registry;

/**
 * Builds a SearchQuery from the current request.
 *
 * Kept out of the controller so the search page, the suggest endpoint and any
 * later category page integration all read the same parameters in the same
 * way. Filter URLs are part of the shop's public surface - one place to change
 * them beats three.
 *
 * Filters arrive as foun10filter[<attributeId>][]=<valueId>.
 *
 * The name is deliberately ours and not OXID's "attrfilter". That parameter is
 * taken, and it means something else:
 *
 *  - ArticleListController::executefilter() reads it and writes the value into
 *    the session as session_attrfilter, which loadArticles(), AttributeList and
 *    Locator all read back. Squatting on the name would push our data into that
 *    mechanism the moment anything triggered that fnc on a category page;
 *  - the shapes do not even match. OXID stores attrfilter[<attributeId>] as a
 *    single attribute *value* ("Beige"); this carries a list of value *IDs* per
 *    attribute, because a facet is multi select.
 *
 * The one thing the core name did give us for free was cache variation: the EE
 * ArticleListController folds attrfilter into generateViewId(), so its page
 * cache varied per filter by itself. Ours does not, and that is not theoretical
 * - a filtered category page was observed being served back for the unfiltered
 * one. foun10\EasySearch\Extension\Application\Controller\ArticleListController
 * overrides generateViewId() to fold PARAM_FILTER in.
 *
 * PARAM_SEARCH stays OXID's own: the search page is entered through the shop's
 * search form and that name is the shop's, not ours.
 */
class RequestQueryFactory
{
    use RequestValues;

    public const PARAM_SEARCH = 'searchparam';
    public const PARAM_FILTER = 'foun10filter';
    public const PARAM_PAGE = 'pgNr';
    public const PARAM_PRICE_FROM = 'foun10pricefrom';
    public const PARAM_PRICE_TO = 'foun10priceto';

    /**
     * Default page size when the shop setting is missing.
     */
    protected const DEFAULT_PAGE_SIZE = 24;

    /**
     * What a filter parameter may carry at most.
     *
     * A customer clicking facets produces a handful of values; these numbers
     * are far above anything the sidebar can even render. They exist because
     * the parameter is public and unauthenticated: without a ceiling, a hand
     * written URL decides how large an IN list the database is handed and how
     * many EXISTS subqueries a single request runs. Anything beyond the limit
     * is cut off rather than refused - the customer still gets a result page.
     */
    protected const MAX_FILTER_ATTRIBUTES = 20;
    protected const MAX_FILTER_VALUES = 50;

    public function fromRequest(?string $sortSql = null): SearchQuery
    {
        $pageSize = $this->getPageSize();

        return new SearchQuery(
            $this->toString($this->getRawParameter(self::PARAM_SEARCH)),
            $this->getShopId(),
            $this->getLanguageId(),
            $this->getFilters(),
            $this->mapSort($sortSql),
            $this->getPage() * $pageSize,
            $pageSize,
            $this->getStringOrNull('searchcnid'),
            $this->getStringOrNull('searchmanufacturer'),
            $this->getFloatOrNull(self::PARAM_PRICE_FROM),
            $this->getFloatOrNull(self::PARAM_PRICE_TO)
        );
    }

    /**
     * The same query, but for a category listing instead of a search.
     *
     * The term stays empty: a category page is narrowed by its category and
     * whatever facets are selected, never by a search term. Everything else -
     * filters, price range, paging, sorting - is read exactly as it is on the
     * search page, which is what keeps the two pages behaving alike.
     */
    public function forCategory(string $categoryId, ?string $sortSql = null): SearchQuery
    {
        $pageSize = $this->getPageSize();

        return new SearchQuery(
            '',
            $this->getShopId(),
            $this->getLanguageId(),
            $this->getFilters(),
            $this->mapSort($sortSql),
            $this->getPage() * $pageSize,
            $pageSize,
            $categoryId,
            null,
            $this->getFloatOrNull(self::PARAM_PRICE_FROM),
            $this->getFloatOrNull(self::PARAM_PRICE_TO)
        );
    }

    /**
     * The same query, but for a manufacturer listing.
     *
     * Identical in shape to forCategory(): no term, narrowed by the thing the
     * page is about plus whatever facets are selected. Kept as its own method
     * rather than a flag, so a caller cannot accidentally pass both.
     */
    public function forManufacturer(string $manufacturerId, ?string $sortSql = null): SearchQuery
    {
        $pageSize = $this->getPageSize();

        return new SearchQuery(
            '',
            $this->getShopId(),
            $this->getLanguageId(),
            $this->getFilters(),
            $this->mapSort($sortSql),
            $this->getPage() * $pageSize,
            $pageSize,
            null,
            $manufacturerId,
            $this->getFloatOrNull(self::PARAM_PRICE_FROM),
            $this->getFloatOrNull(self::PARAM_PRICE_TO)
        );
    }

    /**
     * @return FacetFilter[]
     */
    public function getFilters(): array
    {
        $raw = $this->getEscapedParameter(self::PARAM_FILTER);

        if (!is_array($raw)) {
            return [];
        }

        $filters = [];

        foreach ($raw as $attributeId => $valueIds) {
            $attributeId = (string) $attributeId;

            // Tolerate a bare value instead of a list, so a hand written or
            // hand shortened URL still filters rather than silently doing
            // nothing.
            //
            // Value IDs are checked by the same rule as the attribute ID: they
            // are md5 hashes of the normalised value, so anything else in the
            // parameter is not a facet value and has no business travelling on
            // into a query or back out into a link. toString() carries its
            // share of that: a nested foun10filter[a][b][]=c would otherwise
            // hand strval() an array.
            // array_slice() below reindexes, so the keys left over by
            // array_filter() and array_unique() do not survive either way.
            $valueIds = array_unique(array_filter(
                array_map(fn ($valueId): string => $this->toString($valueId), (array) $valueIds),
                fn (string $valueId): bool => $this->isId($valueId)
            ));

            if ($valueIds === [] || !$this->isId($attributeId)) {
                continue;
            }

            $filters[] = new FacetFilter(
                $attributeId,
                array_slice($valueIds, 0, self::MAX_FILTER_VALUES)
            );

            if (count($filters) >= self::MAX_FILTER_ATTRIBUTES) {
                break;
            }
        }

        return $filters;
    }

    public function getPageSize(): int
    {
        $pageSize = $this->getConfiguredPageSize();

        return $pageSize > 0 ? $pageSize : self::DEFAULT_PAGE_SIZE;
    }

    /**
     * The requested page, zero based.
     *
     * Clamped at zero: a negative pgNr would otherwise turn into a negative
     * OFFSET, which the database rejects outright.
     */
    public function getPage(): int
    {
        return max(0, (int) $this->toString($this->getEscapedParameter(self::PARAM_PAGE)));
    }

    /**
     * Translates OXID's sorting SQL into an engine sort constant.
     *
     * The shop hands sorting around as a fragment like "oxprice asc", so it
     * has to be interpreted rather than passed through - the engine groups
     * variants and needs an aggregate, not a raw column.
     */
    public function mapSort(?string $sortSql): string
    {
        if ($sortSql === null || trim($sortSql) === '') {
            return SearchQuery::SORT_RELEVANCE;
        }

        $sortSql = strtolower($sortSql);
        $isDescending = str_contains($sortSql, 'desc');

        if (str_contains($sortSql, 'oxprice')) {
            return $isDescending ? SearchQuery::SORT_PRICE_DESC : SearchQuery::SORT_PRICE_ASC;
        }

        if (str_contains($sortSql, 'oxtitle')) {
            return SearchQuery::SORT_TITLE_ASC;
        }

        if (str_contains($sortSql, 'oxinsert')) {
            return SearchQuery::SORT_NEWEST;
        }

        if (str_contains($sortSql, 'oxsold')) {
            return SearchQuery::SORT_BESTSELLER;
        }

        return SearchQuery::SORT_RELEVANCE;
    }

    protected function getStringOrNull(string $parameter): ?string
    {
        $value = rawurldecode($this->toString($this->getEscapedParameter($parameter)));

        return $this->isId($value) ? $value : null;
    }

    protected function getFloatOrNull(string $parameter): ?float
    {
        $value = $this->getEscapedParameter($parameter);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * OXID object IDs are 32 character hashes; anything else in a filter
     * parameter is not worth passing on to the engine.
     */
    protected function isId(string $value): bool
    {
        return $value !== '' && preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $value) === 1;
    }

    /*
     * The shop touch points, kept apart from the rules above.
     *
     * All four hand back scalars or raw request values rather than Config,
     * Language or Request objects, so the parameter rules - what counts as an
     * ID, where the filter caps bite, how a sort fragment maps - can be proven
     * without a shop.
     */

    protected function getEscapedParameter(string $parameter): mixed
    {
        return Registry::getRequest()->getRequestEscapedParameter($parameter);
    }

    /**
     * The unescaped value. Only the search term is read this way: it is shown
     * back to the customer and re-encoded by the view, so it must not arrive
     * already escaped.
     */
    protected function getRawParameter(string $parameter): mixed
    {
        return Registry::getRequest()->getRequestParameter($parameter);
    }

    protected function getShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    protected function getLanguageId(): int
    {
        return (int) Registry::getLang()->getBaseLanguage();
    }

    protected function getConfiguredPageSize(): int
    {
        return (int) Registry::getConfig()->getConfigParam('iNrofCatArticles');
    }
}
