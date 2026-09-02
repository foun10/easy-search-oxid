<?php
declare(strict_types=1);

namespace foun10\EasySearch\Extension\Core;

use foun10\EasySearch\Controller\SuggestController;
use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Core\RequestQueryFactory;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Throwable;

/**
 * Values the search templates and the suggest JavaScript need.
 *
 * Kept on ViewConfig so every theme in the chain can reach them without each
 * one wiring up its own controller.
 */
class ViewConfig extends ViewConfig_parent
{
    /**
     * Debounce in milliseconds before the suggest request fires.
     *
     * Long enough that typing a word does not produce one request per
     * character, short enough that the dropdown still feels immediate.
     */
    public const SUGGEST_DELAY_MS = 150;

    /**
     * Endpoint the search box polls. Built through the shop URL so it stays
     * correct per subshop and per language.
     */
    public function getFoun10SuggestUrl(): string
    {
        return Registry::getConfig()->getShopUrl()
            . 'index.php?cl=foun10easysearchsuggest&lang='
            . Registry::getLang()->getBaseLanguage();
    }

    /**
     * Endpoint the filter panel asks while the customer is still choosing.
     * Built like the suggest URL, so it stays correct per subshop and language.
     */
    public function getFoun10FacetUrl(): string
    {
        return Registry::getConfig()->getShopUrl()
            . 'index.php?cl=foun10easysearchfacets&lang='
            . Registry::getLang()->getBaseLanguage();
    }

    public function getFoun10SuggestParam(): string
    {
        return SuggestController::PARAM_TERM;
    }

    /**
     * Characters required before the first request goes out.
     */
    public function getFoun10SuggestMinLength(): int
    {
        $moduleSettings = $this->foun10GetModuleSettings();

        return $moduleSettings !== null ? max(1, $moduleSettings->getMinTermLength()) : 2;
    }

    public function getFoun10SuggestDelay(): int
    {
        return self::SUGGEST_DELAY_MS;
    }

    /**
     * False when the module is installed but not configured, so the templates
     * can keep rendering the plain search box.
     */
    public function isFoun10SuggestActive(): bool
    {
        return $this->foun10GetModuleSettings() !== null;
    }

    /**
     * Whether the search page prints the correction notice.
     */
    /**
     * Whether the customer is looking at a filtered list.
     *
     * On ViewConfig rather than on the listing controllers, although those
     * carry hasFoun10ActiveFilters() through FacetPresentation: a template that
     * asks about filters is not necessarily rendered by one of them - the list
     * template serves categories, manufacturers and vendors - and a missing
     * method takes the page down. ViewConfig is there in every template.
     *
     * Read through RequestQueryFactory so the rule about what counts as a
     * filter lives in one place: a value the query would refuse is not an
     * active filter here either.
     */
    public function hasFoun10ActiveFilters(): bool
    {
        try {
            /** @var RequestQueryFactory $factory */
            $factory = ContainerFactory::getInstance()
                ->getContainer()
                ->get(RequestQueryFactory::class);
        } catch (Throwable $exception) {
            return false;
        }

        foreach ($factory->getFilters() as $filter) {
            if (!$filter->isEmpty()) {
                return true;
            }
        }

        $request = Registry::getRequest();

        foreach ([RequestQueryFactory::PARAM_PRICE_FROM, RequestQueryFactory::PARAM_PRICE_TO] as $parameter) {
            if (is_numeric($request->getRequestEscapedParameter($parameter))) {
                return true;
            }
        }

        return false;
    }

    public function isFoun10CorrectionVisible(): bool
    {
        $moduleSettings = $this->foun10GetModuleSettings();

        return $moduleSettings === null || $moduleSettings->isCorrectionHintVisible();
    }

    protected function foun10GetModuleSettings(): ?ModuleSettings
    {
        try {
            /** @var ModuleSettings $moduleSettings */
            $moduleSettings = ContainerFactory::getInstance()
                ->getContainer()
                ->get(ModuleSettings::class);

            return $moduleSettings;
        } catch (Throwable $exception) {
            return null;
        }
    }
}
