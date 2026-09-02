<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;

/**
 * Typed access to the module settings.
 *
 * All values are resolved per subshop, so the shops of one installation can
 * run different facet sets and correction thresholds from the same code.
 *
 * Every read is memoised, keyed by the shop it was read for. Not premature:
 * OXID resolves a module setting through the file-based project configuration,
 * and one read measured **15 ms**. The facet sidebar asks for the value limit
 * once per facet, so eight facets spent 120 ms answering the same question
 * eight times - on both connectors, and on a page where the search itself
 * takes single-digit milliseconds.
 */
class ModuleSettings
{
    public const MODULE_ID = 'foun10EasySearch';

    public const ENGINE_MYSQL = 'mysql';
    public const ENGINE_MEILISEARCH = 'meilisearch';
    public const ENGINE_NULL = 'null';

    /**
     * Values already read, per shop: [shopId][name] => value.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $values = [];

    public function __construct(
        protected ModuleSettingServiceInterface $moduleSettingService
    ) {
    }

    public function getEngine(): string
    {
        return (string) $this->readString('FOUN10EASYSEARCH_ENGINE');
    }

    /**
     * Fallback for the Meilisearch host, used when the environment does not
     * name one. MeiliConfiguration reads MEILI_HOST first - a URL committed
     * into var/configuration would otherwise follow the release from staging
     * into production.
     */
    public function getMeiliHost(): string
    {
        return $this->getOptionalString('FOUN10EASYSEARCH_MEILI_HOST');
    }

    public function getMeiliApiKey(): string
    {
        return $this->getOptionalString('FOUN10EASYSEARCH_MEILI_KEY');
    }

    public function getMeiliIndexPrefix(): string
    {
        return $this->getOptionalString('FOUN10EASYSEARCH_MEILI_PREFIX');
    }

    /**
     * A setting that may not exist yet.
     *
     * Settings only reach the shop through oe:module:install and
     * oe:module:deploy-configurations, so between a deployment that adds one
     * and the command that pushes it, a read throws. For the connector settings
     * that must not take the shop down: an empty answer means "not configured",
     * and the defaults take over.
     */
    protected function getOptionalString(string $name): string
    {
        return trim((string) $this->remember($name, function () use ($name): string {
            try {
                return (string) $this->moduleSettingService->getString($name, self::MODULE_ID);
            } catch (\Throwable $exception) {
                return '';
            }
        }));
    }

    protected function readString(string $name): string
    {
        return (string) $this->remember(
            $name,
            fn (): string => (string) $this->moduleSettingService->getString($name, self::MODULE_ID)
        );
    }

    protected function readInteger(string $name): int
    {
        return (int) $this->remember(
            $name,
            fn (): int => (int) $this->moduleSettingService->getInteger($name, self::MODULE_ID)
        );
    }

    protected function readBoolean(string $name): bool
    {
        return (bool) $this->remember(
            $name,
            fn (): bool => (bool) $this->moduleSettingService->getBoolean($name, self::MODULE_ID)
        );
    }

    /**
     * Keyed by shop, not just by name: a request serves one shop, but a console
     * command walks all four, and a value cached across that switch would be
     * the wrong shop's.
     */
    protected function remember(string $name, callable $read): mixed
    {
        $shopId = $this->getShopId();

        if (!array_key_exists($name, $this->values[$shopId] ?? [])) {
            $this->values[$shopId][$name] = $read();
        }

        return $this->values[$shopId][$name];
    }

    /**
     * The shop the current values belong to.
     *
     * Its own method so the memoisation above can be exercised without a shop:
     * a scalar crosses the seam rather than an OXID object, which keeps the
     * unit suite free of the framework. Same shape as
     * VisibilityResolver::isStockEnabled().
     */
    protected function getShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    /**
     * Whether searches are counted for the top and zero-hit lists.
     */
    public function isSearchLogEnabled(): bool
    {
        return $this->readBoolean('FOUN10EASYSEARCH_LOG_ENABLED');
    }

    /**
     * Whether a variant inherits the attributes assigned to its parent.
     *
     * Depends entirely on how the catalogue is maintained. Where the parent
     * carries only what all its variants share - material, care symbols -
     * inheriting is right. Where it carries the union of its variants' values,
     * as some ERP exports do, every variant ends up claiming every size its
     * siblings have, and the size filter stops meaning anything.
     */
    public function useParentAttributes(): bool
    {
        return $this->readBoolean('FOUN10EASYSEARCH_PARENT_ATTRIBUTES');
    }

    public function getMinTermLength(): int
    {
        return $this->readInteger('FOUN10EASYSEARCH_MIN_TERM_LENGTH');
    }

    public function isCorrectionEnabled(): bool
    {
        return $this->readBoolean('FOUN10EASYSEARCH_CORRECTION_ENABLED');
    }

    /**
     * Whether a correction may replace the entered term automatically, or
     * should only ever be offered as "Did you mean ...?".
     */
    public function isCorrectionAutoApplied(): bool
    {
        return $this->readBoolean('FOUN10EASYSEARCH_CORRECTION_AUTO_APPLY');
    }

    /**
     * Correction only kicks in at or below this hit count. 0 means: only when
     * the original term found nothing at all.
     */
    public function getCorrectionMaxHits(): int
    {
        return $this->readInteger('FOUN10EASYSEARCH_CORRECTION_MAX_HITS');
    }

    /**
     * Dictionary terms below this catalogue frequency are not offered as a
     * correction - guards against correcting towards typos that made it into
     * product data.
     */
    public function getCorrectionMinFrequency(): int
    {
        return $this->readInteger('FOUN10EASYSEARCH_CORRECTION_MIN_FREQUENCY');
    }

    /**
     * Whether the frontend shows the "showing results for ..." notice at all.
     *
     * Separate from FOUN10EASYSEARCH_CORRECTION_ENABLED on purpose: correction can
     * keep rescuing zero-hit searches while the notice stays hidden, which is
     * what a shop wants if the wording does not fit its tone.
     */
    public function isCorrectionHintVisible(): bool
    {
        return $this->readBoolean('FOUN10EASYSEARCH_SHOW_CORRECTION');
    }

    public function getFacetValueLimit(): int
    {
        return $this->readInteger('FOUN10EASYSEARCH_FACET_VALUE_LIMIT');
    }

    public function getSuggestTermLimit(): int
    {
        return $this->readInteger('FOUN10EASYSEARCH_SUGGEST_LIMIT_TERMS');
    }

    public function getSuggestProductLimit(): int
    {
        return $this->readInteger('FOUN10EASYSEARCH_SUGGEST_LIMIT_PRODUCTS');
    }
}
