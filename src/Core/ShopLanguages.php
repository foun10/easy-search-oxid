<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use OxidEsales\Eshop\Core\Registry;

/**
 * The languages a shop actually serves.
 *
 * Worth having in one place because OXID makes this easy to get wrong:
 * getLanguageIds() and getActiveShopLanguageIds() both return every language
 * that is *configured*, active or not - despite the second one being named for
 * exactly the opposite. Only getLanguageArray(null, true) honours the active
 * flag, and that one answers for the shop currently in context.
 *
 * Getting it wrong is quiet rather than loud: the admin offers a label field
 * for a language nobody can reach, and a rebuild spends minutes indexing a
 * catalogue no customer will ever search.
 */
class ShopLanguages
{
    /**
     * Languages of a shop that are switched on.
     *
     * $shopId is only needed away from the current shop - the console walks all
     * four, and getLanguageArray() would answer for whichever one happens to be
     * in context. There the flags are read from that shop's own configuration
     * instead.
     *
     * @return array<int, array{id: int, abbr: string, name: string}>
     */
    public function getActive(?int $shopId = null): array
    {
        $languages = $shopId === null || $shopId === $this->getCurrentShopId()
            ? $this->getFromContext()
            : $this->getFromShopConfiguration($shopId);

        // A shop with no language flagged active would otherwise offer nothing
        // to index and nothing to configure, which is never what is meant.
        return $languages === [] ? [['id' => 0, 'abbr' => 'de', 'name' => 'de']] : $languages;
    }

    /**
     * @return int[]
     */
    public function getActiveIds(?int $shopId = null): array
    {
        return array_map(
            static fn (array $language): int => $language['id'],
            $this->getActive($shopId)
        );
    }

    public function isActive(int $langId, ?int $shopId = null): bool
    {
        return in_array($langId, $this->getActiveIds($shopId), true);
    }

    /**
     * The shop currently in context.
     *
     * Its own method so the decision above - context or foreign shop - can be
     * exercised without a shop, and so a scalar rather than an OXID object
     * crosses the seam. Same shape as ModuleSettings::getShopId().
     */
    protected function getCurrentShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    /**
     * @return array<int, array{id: int, abbr: string, name: string}>
     */
    protected function getFromContext(): array
    {
        $languages = [];

        foreach (Registry::getLang()->getLanguageArray(null, true) as $language) {
            $languages[] = $this->toEntry(
                (int) ($language->id ?? 0),
                (string) ($language->abbr ?? ''),
                (string) ($language->name ?? '')
            );
        }

        return $languages;
    }

    /**
     * @return array<int, array{id: int, abbr: string, name: string}>
     */
    protected function getFromShopConfiguration(int $shopId): array
    {
        $config = Registry::getConfig();
        $parameters = $config->getShopConfVar('aLanguageParams', $shopId);
        $names = (array) $config->getShopConfVar('aLanguages', $shopId);

        if (!is_array($parameters) || $parameters === []) {
            return [];
        }

        $languages = [];

        foreach ($parameters as $abbr => $parameter) {
            if (empty($parameter['active'])) {
                continue;
            }

            $languages[] = $this->toEntry(
                (int) ($parameter['baseId'] ?? 0),
                (string) $abbr,
                (string) ($names[$abbr] ?? '')
            );
        }

        return $languages;
    }

    /**
     * @return array{id: int, abbr: string, name: string}
     */
    protected function toEntry(int $id, string $abbr, string $name): array
    {
        $abbr = $abbr !== '' ? $abbr : (string) $id;

        return [
            'id' => $id,
            'abbr' => $abbr,
            'name' => $name !== '' ? $name : $abbr,
        ];
    }
}
