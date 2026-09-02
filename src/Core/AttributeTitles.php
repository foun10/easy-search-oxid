<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\TableViewNameGenerator;

/**
 * The names of attributes, as the shop knows them.
 *
 * Its own service because both connectors need the same answer: a facet
 * sidebar built from Meilisearch has to be labelled exactly like one built from
 * MySQL, or a comparison between the two would show differences that are only
 * in the wording.
 */
class AttributeTitles
{
    /**
     * @var array<string, array<string, string>>
     */
    protected array $cache = [];

    /**
     * @param string[] $attributeIds
     *
     * @return array<string, string> Titles keyed by attribute ID
     */
    public function get(array $attributeIds, int $shopId, int $langId): array
    {
        if ($attributeIds === []) {
            return [];
        }

        $cacheKey = $shopId . '_' . $langId . '_' . md5(implode(',', $attributeIds));

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $database = DatabaseProvider::getDb();
        $attributeView = Registry::get(TableViewNameGenerator::class)
            ->getViewName('oxattribute', $langId, $shopId);
        $quotedIds = implode(', ', $database->quoteArray($attributeIds));

        $titles = [];

        foreach (DatabaseHelper::fetchAll("SELECT OXID, OXTITLE FROM {$attributeView} WHERE OXID IN ({$quotedIds})") as $row) {
            $titles[(string) $row['OXID']] = (string) $row['OXTITLE'];
        }

        return $this->cache[$cacheKey] = $titles;
    }
}
