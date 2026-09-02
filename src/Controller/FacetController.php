<?php
declare(strict_types=1);

namespace foun10\EasySearch\Controller;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Core\RequestQueryFactory;
use foun10\EasySearch\Core\RequestValues;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Engine\Result\SearchResult;
use foun10\EasySearch\Engine\SearchEngineInterface;
use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Throwable;

/**
 * JSON endpoint behind the filter panel: index.php?cl=foun10easysearchfacets&…
 *
 * The offcanvas used to be a set of links: every click reloaded the whole
 * listing to find out what was still selectable. This answers the same question
 * without the reload - hand it the selection the customer has assembled so far
 * and it returns which values remain reachable and how many products the
 * selection would yield.
 *
 * Deliberately returns no products. The list behind the panel does not change
 * while the customer is still choosing; only the count on the apply button
 * does, and the customer navigates once, when they are done.
 *
 * Reads its context through RequestQueryFactory, exactly like the three
 * listing controllers, so search, category and manufacturer pages are the same
 * request with a different parameter - and a filter that would not survive into
 * a listing URL cannot survive into this one either.
 *
 * Failures answer with an empty payload. The panel still holds server-rendered
 * links underneath, so a customer whose request fails keeps the behaviour they
 * had before this endpoint existed.
 */
class FacetController extends FrontendController
{
    use RequestValues;

    public const PARAM_CATEGORY = 'cnid';
    public const PARAM_MANUFACTURER = 'mnid';

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
        try {
            return $this->getFacets();
        } catch (Throwable $exception) {
            $this->logError(
                'foun10EasySearch: facet request failed - ' . $exception->getMessage(),
                $exception
            );

            return $this->getEmptyPayload();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getFacets(): array
    {
        /** @var SearchEngineInterface $engine */
        $engine = $this->getService(SearchEngineInterface::class);

        if (!$engine->isAvailable($this->getCurrentShopId(), $this->getCurrentLanguageId())) {
            return $this->getEmptyPayload();
        }

        /** @var RequestQueryFactory $factory */
        $factory = $this->getService(RequestQueryFactory::class);
        /** @var ModuleSettings $moduleSettings */
        $moduleSettings = $this->getService(ModuleSettings::class);

        return $this->renderPayload(
            $engine->search($this->buildQuery($factory)),
            $moduleSettings->getFacetValueLimit()
        );
    }

    /**
     * The same query the page behind the panel would run, minus the products.
     */
    protected function buildQuery(RequestQueryFactory $factory): SearchQuery
    {
        $request = $this->getRequest();
        $categoryId = trim($this->toString($request->getRequestEscapedParameter(self::PARAM_CATEGORY)));
        $manufacturerId = trim($this->toString($request->getRequestEscapedParameter(self::PARAM_MANUFACTURER)));

        if ($categoryId !== '') {
            $query = $factory->forCategory($categoryId);
        } elseif ($manufacturerId !== '') {
            $query = $factory->forManufacturer($manufacturerId);
        } else {
            $query = $factory->fromRequest();
        }

        return $query->withLimit(1);
    }

    /**
     * @return array<string, mixed>
     */
    protected function renderPayload(SearchResult $result, int $valueLimit): array
    {
        return [
            'total' => $result->getTotalCount(),
            'facets' => array_map(
                fn (Facet $facet): array => $this->renderFacet($facet, $valueLimit),
                $result->getFacets()
            ),
        ];
    }

    /**
     * One facet as the panel needs it.
     *
     * Availability rather than a number: the panel strikes through what leads
     * nowhere and puts the only figure a customer sees - the total - on the
     * apply button. Counts are still computed inside the engine, because a
     * value at zero is what makes something unavailable, but they stop here.
     *
     * @return array<string, mixed>
     */
    protected function renderFacet(Facet $facet, int $valueLimit): array
    {
        $values = [];

        foreach ($facet->getValues() as $value) {
            $values[] = [
                'id' => $value->getValueId(),
                'label' => $value->getLabel(),
                'hex' => $value->getHexCode(),
                'selected' => $value->isSelected(),
                'available' => $value->isSelectable(),
            ];
        }

        return [
            'id' => $facet->getAttributeId(),
            'title' => $facet->getTitle(),
            'type' => $facet->getType(),
            'selected' => count($facet->getSelectedValues()),
            // A value the answer does not mention is one that leads nowhere -
            // unless the list was cut off at the display limit, in which case
            // its absence says nothing and the panel leaves it alone.
            'truncated' => count($values) >= $valueLimit,
            'values' => $values,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getEmptyPayload(): array
    {
        return ['total' => 0, 'facets' => []];
    }

    /*
     * The shop touch points. The two below the request carry the response out
     * rather than a Utils object, so the headers this endpoint sets - and the
     * Cache-Control one is a decision, not a formality - stay assertable.
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
