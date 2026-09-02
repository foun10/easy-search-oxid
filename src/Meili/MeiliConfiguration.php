<?php
declare(strict_types=1);

namespace foun10\EasySearch\Meili;

use foun10\EasySearch\Core\ModuleSettings;

/**
 * Where Meilisearch lives and what the indexes are called.
 *
 * Host and key come from the environment first and from the module settings
 * only as a fallback. That order is deliberate: the module settings are
 * exported into var/configuration and deployed to every environment, so a
 * staging URL committed there would follow the release to production. The
 * container sets MEILI_HOST and MEILI_MASTER_KEY, and the settings exist for
 * the case where a shop has no way to set environment variables.
 *
 * One index per shop and language rather than one big index with a shopId
 * filter. Meilisearch settings - searchable attributes, filterable attributes,
 * ranking - are per index, and the four subshops configure different facet
 * attributes. A shared index would have to carry the union of all of them and
 * could not answer "is this shop indexed at all".
 */
class MeiliConfiguration
{
    public const DEFAULT_HOST = 'http://meilisearch:7700';
    public const DEFAULT_PREFIX = 'foun10easysearch';

    /**
     * Suffix of the index a full rebuild fills before it is swapped in. The
     * counterpart of the MySql writer's shadow tables.
     */
    public const SUFFIX_SHADOW = '_tmp';

    public function __construct(
        protected ModuleSettings $moduleSettings
    ) {
    }

    public function getHost(): string
    {
        $host = $this->fromEnvironment('MEILI_HOST');

        if ($host === '') {
            $host = $this->moduleSettings->getMeiliHost();
        }

        return rtrim($host !== '' ? $host : self::DEFAULT_HOST, '/');
    }

    public function getApiKey(): string
    {
        $key = $this->fromEnvironment('MEILI_MASTER_KEY');

        if ($key === '') {
            $key = $this->fromEnvironment('MEILI_API_KEY');
        }

        return $key !== '' ? $key : $this->moduleSettings->getMeiliApiKey();
    }

    /**
     * Lets several shops - or a developer's second checkout - share one
     * Meilisearch instance without colliding on index names.
     */
    public function getIndexPrefix(): string
    {
        $prefix = $this->fromEnvironment('MEILI_INDEX_PREFIX');

        if ($prefix === '') {
            $prefix = $this->moduleSettings->getMeiliIndexPrefix();
        }

        return $prefix !== '' ? $prefix : self::DEFAULT_PREFIX;
    }

    public function getIndexUid(int $shopId, int $langId): string
    {
        return sprintf('%s_s%d_l%d', $this->getIndexPrefix(), $shopId, $langId);
    }

    public function getShadowIndexUid(int $shopId, int $langId): string
    {
        return $this->getIndexUid($shopId, $langId) . self::SUFFIX_SHADOW;
    }

    public function isShadowIndexUid(string $uid): bool
    {
        return str_ends_with($uid, self::SUFFIX_SHADOW);
    }

    /**
     * Reads a value the container may have set.
     *
     * getenv() alone is not enough: php-fpm passes variables through
     * $_SERVER/$_ENV depending on how the pool is configured, and the CLI and
     * the web request do not always agree on which one is populated.
     */
    protected function fromEnvironment(string $name): string
    {
        foreach ([getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
