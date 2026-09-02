<?php
declare(strict_types=1);

namespace foun10\EasySearch\Extension\Application\Controller;

use foun10\EasySearch\Core\RequestQueryFactory;
use foun10\EasySearch\Engine\Result\Correction;
use foun10\EasySearch\Extension\FacetPresentation;
use OxidEsales\Eshop\Core\Registry;

/**
 * Exposes facets and the spelling correction to the search template.
 *
 * The heavy lifting happens in the Search model extension; by the time render()
 * runs, the engine result is already parked in the SearchResultProvider. This
 * class only presents it, plus the URL helpers the filter sidebar needs to
 * build toggle links.
 *
 * Everything degrades quietly: with no engine result the getters return empty
 * values and the template renders the plain result list, exactly as before the
 * module existed.
 */
class SearchController extends SearchController_parent
{
    use FacetPresentation;

    /**
     * Set when the engine corrected the query, either silently or as an offer.
     * Drives the "showing results for ..." line above the list.
     */
    public function getFoun10SearchCorrection(): ?Correction
    {
        $result = $this->foun10GetResult();

        return $result !== null ? $result->getCorrection() : null;
    }

    /**
     * The term the displayed results actually belong to.
     *
     * getSearchParamForHtml() keeps returning what the customer typed, which is
     * right for the input field but wrong for the result headline: after an
     * applied correction the hit count belongs to the corrected term, and
     * pairing the two reads as a lie ("294 hits for bluse" when bluse found
     * nothing).
     */
    public function getFoun10EffectiveSearchParam(): string
    {
        $correction = $this->getFoun10SearchCorrection();

        if ($correction !== null && $correction->isApplied()) {
            return $correction->getCorrected();
        }

        return (string) $this->getSearchParamForHtml();
    }

    /**
     * URL that repeats the search with the customer's original spelling, for
     * the "search instead for ..." escape hatch after an auto-correction.
     */
    public function getFoun10UncorrectedSearchUrl(): string
    {
        $correction = $this->getFoun10SearchCorrection();

        if ($correction === null) {
            return '';
        }

        $parameters = $this->foun10GetBaseParameters();
        $parameters[RequestQueryFactory::PARAM_SEARCH] = $correction->getOriginal();
        $parameters['foun10nocorrection'] = 1;

        return $this->foun10BuildUrl($parameters);
    }

    /**
     * Parameters every generated link has to carry: the search term and the
     * current filters. Paging is dropped on purpose - changing a filter always
     * returns to page one.
     *
     * @return array<string, mixed>
     */
    protected function foun10GetBaseParameters(): array
    {
        $request = Registry::getRequest();
        $parameters = ['cl' => 'search'];

        $searchParam = (string) $request->getRequestParameter(RequestQueryFactory::PARAM_SEARCH);

        if ($searchParam !== '') {
            $parameters[RequestQueryFactory::PARAM_SEARCH] = $searchParam;
        }

        $filters = $this->foun10GetSelectedFilters();

        if ($filters !== []) {
            $parameters[RequestQueryFactory::PARAM_FILTER] = $filters;
        }

        foreach (['searchcnid', 'searchmanufacturer', 'listorderby', 'listorder'] as $passThrough) {
            $value = (string) $request->getRequestEscapedParameter($passThrough);

            if ($value !== '') {
                $parameters[$passThrough] = $value;
            }
        }

        return $parameters;
    }
}
