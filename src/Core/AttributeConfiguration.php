<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\TableViewNameGenerator;

/**
 * Which attributes are filterable, which are searchable, and in what order the
 * filters appear.
 *
 * Lives in foun10easysearchattribute rather than in the module settings: settings
 * are written to var/configuration and pushed to the database by
 * oe:module:deploy-configurations, so every release would overwrite whatever a
 * merchant configured. A table is left alone by deployment.
 *
 * Reads are memoised per shop for the request. Both lists are asked for on
 * every indexed document and on every facet, and neither changes mid request.
 */
class AttributeConfiguration
{
    public const TABLE = 'foun10easysearchattribute';
    public const TABLE_TITLE = 'foun10easysearchattributetitle';

    /**
     * Attributes per sample query. Small enough to keep the statement readable,
     * large enough that a full catalogue takes a handful of round trips.
     */
    protected const SAMPLE_CHUNK = 30;

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    protected array $cache = [];

    /**
     * @var array<string, array<string, string>>
     */
    protected array $titleCache = [];

    /**
     * Attribute IDs offered as filters, in the order the merchant arranged
     * them - that order is what the sidebar renders.
     *
     * Independent of the searchable list: the two roles overlap freely, and
     * most filterable attributes are searchable as well.
     *
     * @return string[]
     */
    public function getFacetAttributeIds(int $shopId): array
    {
        $ids = [];

        foreach ($this->getRows($shopId) as $row) {
            if ((int) $row['FOUN10FACET'] === 1) {
                $ids[] = (string) $row['FOUN10ATTRID'];
            }
        }

        return $ids;
    }

    /**
     * Attribute IDs whose values feed the searchable text.
     *
     * An empty list means no restriction - every attribute is searchable -
     * which keeps a fresh install working before anything is configured.
     *
     * @return string[]
     */
    public function getSearchableAttributeIds(int $shopId): array
    {
        $ids = [];

        foreach ($this->getRows($shopId) as $row) {
            if ((int) $row['FOUN10EASYSEARCHABLE'] === 1) {
                $ids[] = (string) $row['FOUN10ATTRID'];
            }
        }

        return $ids;
    }

    /**
     * Everything configured for a shop, in the order it was arranged.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRows(int $shopId): array
    {
        if (isset($this->cache[$shopId])) {
            return $this->cache[$shopId];
        }

        $sql = '
            SELECT FOUN10ATTRID, FOUN10FACET, FOUN10EASYSEARCHABLE, FOUN10DISPLAY, FOUN10SORT
            FROM ' . self::TABLE . '
            WHERE OXSHOPID = :shopId
            ORDER BY FOUN10SORT ASC';

        try {
            $rows = DatabaseHelper::fetchAll($sql, [':shopId' => $shopId]);
        } catch (\Throwable $exception) {
            // Table not migrated yet: behave like nothing is configured rather
            // than taking the shop down.
            $rows = [];
        }

        return $this->cache[$shopId] = $rows;
    }

    /**
     * How each configured attribute should be rendered, keyed by attribute ID.
     *
     * @return array<string, string>
     */
    public function getDisplayModes(int $shopId): array
    {
        $modes = [];

        foreach ($this->getRows($shopId) as $row) {
            $modes[(string) $row['FOUN10ATTRID']] = FacetDisplay::normalize(
                isset($row['FOUN10DISPLAY']) ? (string) $row['FOUN10DISPLAY'] : null
            );
        }

        return $modes;
    }

    public function getDisplayMode(int $shopId, string $attributeId): string
    {
        return $this->getDisplayModes($shopId)[$attributeId] ?? FacetDisplay::MODE_DEFAULT;
    }

    /**
     * Customer facing labels for one language, keyed by attribute ID.
     *
     * Only attributes somebody actually renamed appear. The caller falls back
     * to the attribute's own title, so an empty table means the shop behaves
     * exactly as it did before labels existed.
     *
     * @return array<string, string>
     */
    public function getCustomTitles(int $shopId, int $langId): array
    {
        $key = $shopId . '_' . $langId;

        if (isset($this->titleCache[$key])) {
            return $this->titleCache[$key];
        }

        $sql = '
            SELECT FOUN10ATTRID, FOUN10TITLE
            FROM ' . self::TABLE_TITLE . '
            WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId';

        try {
            $rows = DatabaseHelper::fetchAll($sql, [':shopId' => $shopId, ':langId' => $langId]);
        } catch (\Throwable $exception) {
            // Table not migrated yet: no custom labels, not a broken shop.
            $rows = [];
        }

        $titles = [];

        foreach ($rows as $row) {
            $title = trim((string) $row['FOUN10TITLE']);

            if ($title !== '') {
                $titles[(string) $row['FOUN10ATTRID']] = $title;
            }
        }

        return $this->titleCache[$key] = $titles;
    }

    /**
     * Replaces the whole configuration for one shop.
     *
     * Written as delete plus insert on purpose: the admin screen always submits
     * the complete picture, and a diff would have to guess at removals.
     *
     * @param array<int, array{attributeId: string, facet: bool, searchable: bool, display?: string, titles?: array<int, string>}> $entries
     */
    public function save(int $shopId, array $entries): void
    {
        $database = DatabaseProvider::getDb();

        $database->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE OXSHOPID = :shopId',
            [':shopId' => $shopId]
        );

        $values = [];
        $sort = 0;

        foreach ($entries as $entry) {
            $attributeId = trim((string) ($entry['attributeId'] ?? ''));

            if ($attributeId === '') {
                continue;
            }

            $isFacet = !empty($entry['facet']);
            $isSearchable = !empty($entry['searchable']);

            // An attribute that is neither is simply not configured.
            if (!$isFacet && !$isSearchable) {
                continue;
            }

            $sort += 10;

            $values[] = '(' . implode(', ', [
                $database->quote(md5($shopId . '_' . $attributeId)),
                $shopId,
                $database->quote($attributeId),
                $isFacet ? 1 : 0,
                $isSearchable ? 1 : 0,
                $database->quote(FacetDisplay::normalize($entry['display'] ?? null)),
                // Stored for every entry, not just facets, so the admin list
                // comes back in the order it was arranged.
                $sort,
            ]) . ')';
        }

        unset($this->cache[$shopId]);

        if ($values === []) {
            return;
        }

        $database->execute(
            'INSERT INTO ' . self::TABLE . '
                (OXID, OXSHOPID, FOUN10ATTRID, FOUN10FACET, FOUN10EASYSEARCHABLE, FOUN10DISPLAY, FOUN10SORT)
             VALUES ' . implode(', ', $values)
        );
    }

    /**
     * Replaces the custom labels of one shop.
     *
     * Blank means "use the attribute's own title", so an emptied field removes
     * the row rather than storing an empty string - otherwise the fallback
     * could never be reached again.
     *
     * @param array<string, array<int, string>> $titles Attribute ID => language ID => label
     */
    public function saveTitles(int $shopId, array $titles): void
    {
        $database = DatabaseProvider::getDb();

        $database->execute(
            'DELETE FROM ' . self::TABLE_TITLE . ' WHERE OXSHOPID = :shopId',
            [':shopId' => $shopId]
        );

        $this->titleCache = [];

        $values = [];

        foreach ($titles as $attributeId => $perLanguage) {
            $attributeId = trim((string) $attributeId);

            if ($attributeId === '') {
                continue;
            }

            foreach ($perLanguage as $langId => $title) {
                $langId = (int) $langId;
                $title = trim((string) $title);

                if ($title === '') {
                    continue;
                }

                $values[] = '(' . implode(', ', [
                    $database->quote(md5($shopId . '_' . $attributeId . '_' . $langId)),
                    $shopId,
                    $database->quote($attributeId),
                    $langId,
                    $database->quote(mb_substr($title, 0, 255)),
                ]) . ')';
            }
        }

        if ($values === []) {
            return;
        }

        $database->execute(
            'INSERT INTO ' . self::TABLE_TITLE . '
                (OXID, OXSHOPID, FOUN10ATTRID, FOUN10LANGID, FOUN10TITLE)
             VALUES ' . implode(', ', $values)
        );
    }

    /**
     * A few example values per attribute, so the admin screen can show what an
     * attribute actually contains before it is promoted to a filter.
     *
     * oxobject2attribute holds millions of rows and carries only a plain index
     * on OXATTRID, so this is written to stay cheap:
     *
     *  - DISTINCT with LIMIT lets the database stop as soon as it has enough
     *    distinct values. GROUP BY cannot - it groups every row for the
     *    attribute before LIMIT applies, measured at 10 seconds against an
     *    attribute holding 1.5 million rows;
     *  - the per attribute selects are batched into a handful of UNION ALL
     *    statements, so 100 attributes cost a few round trips instead of 100.
     *
     * Measured at roughly 1 second per 30 attributes on a 10 million row table.
     * That is real but acceptable here: the screen is opened rarely, and the
     * values shown are the attribute's actual distinct values rather than a
     * sample of the first rows.
     *
     * @param string[] $attributeIds
     *
     * @return array<string, string[]> Sample values keyed by attribute ID
     */
    public function getValueSamples(array $attributeIds, int $shopId, int $langId, int $limit = 5): array
    {
        if ($attributeIds === []) {
            return [];
        }

        $database = DatabaseProvider::getDb();
        $view = Registry::get(TableViewNameGenerator::class)
            ->getViewName('oxobject2attribute', $langId, $shopId);

        $samples = [];

        foreach (array_chunk(array_values($attributeIds), self::SAMPLE_CHUNK) as $chunk) {
            $selects = [];

            foreach ($chunk as $attributeId) {
                $quoted = $database->quote($attributeId);
                $selects[] = "(SELECT DISTINCT {$quoted} AS ATTRID, OXVALUE
                    FROM {$view}
                    WHERE OXATTRID = {$quoted} AND OXVALUE != ''
                    LIMIT {$limit})";
            }

            // Wrapped in a derived table so the statement starts with SELECT.
            // OXID's database layer returns zero rows for anything beginning
            // with an opening bracket - no error, just an empty result - so a
            // bare "(SELECT ...) UNION ALL (SELECT ...)" silently yields
            // nothing even though the same SQL works in a mysql client.
            $sql = 'SELECT ATTRID, OXVALUE FROM (' . implode(' UNION ALL ', $selects) . ') samples';

            foreach (DatabaseHelper::fetchAll($sql) as $row) {
                $samples[(string) $row['ATTRID']][] = trim((string) $row['OXVALUE']);
            }
        }

        return $samples;
    }

    /**
     * All attributes that exist in the shop, for the admin screen to offer.
     *
     * @return array<string, string> Title keyed by attribute ID
     */
    public function getAvailableAttributes(int $shopId, int $langId): array
    {
        $attributeView = Registry::get(TableViewNameGenerator::class)
            ->getViewName('oxattribute', $langId, $shopId);

        $rows = DatabaseHelper::fetchAll(
            "SELECT OXID, OXTITLE FROM {$attributeView} ORDER BY OXTITLE ASC"
        );

        $attributes = [];

        foreach ($rows as $row) {
            $title = trim((string) $row['OXTITLE']);
            $attributes[(string) $row['OXID']] = $title !== '' ? $title : (string) $row['OXID'];
        }

        return $attributes;
    }
}
