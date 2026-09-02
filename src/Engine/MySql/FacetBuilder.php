<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\MySql;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\ColorValue;
use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Core\FacetAssembler;
use foun10\EasySearch\Core\FacetDisplay;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Index\MySql\IndexTables;
use OxidEsales\Eshop\Core\DatabaseProvider;
use Throwable;

/**
 * Counts the filter sidebar for a result set.
 *
 * Counting is done with each facet's own selection removed - without that rule,
 * clicking "red" would show every other colour at zero and the customer could
 * never widen the selection again.
 *
 * That only forces a separate query for facets which actually carry a
 * selection. All the unselected ones share one condition set and are answered
 * by a single grouped query, which is the whole cost on the page customers see
 * most: nothing selected, one query instead of one per facet.
 *
 * Counted against foun10easysearchindexattributegroup, which already holds one row
 * per product rather than one per variant. That table exists because collapsing
 * variants at query time was the single most expensive thing a search did: a
 * term matching 596 products matches 40,465 variants, and counting through them
 * measured 900 ms against 280 ms here. The variant level query is kept as a
 * fallback for an index that has not been rebuilt since the table was added.
 *
 * One consequence is worth knowing. A product's values are collapsed before the
 * count, so with one facet already selected a second facet counts products that
 * carry the value on *some* variant rather than on the matching one - a red
 * blouse in 38 and a black one in 40 count towards "38" under colour red. With
 * nothing selected, which is the expensive case and the common one, the count is
 * exact. The variant level query below is what exactness would cost.
 *
 * What the counted values are then called and which facets survive into the
 * sidebar is FacetAssembler's job, shared with the Meilisearch connector.
 */
class FacetBuilder
{
    /**
     * Whether a scope has product level rows to count against, per shop and
     * language. Asked once per request - the answer cannot change while one is
     * being served.
     *
     * @var array<string, bool>
     */
    protected array $hasGroupCounts = [];

    public function __construct(
        protected ConditionBuilder $conditionBuilder,
        protected AttributeConfiguration $attributeConfiguration,
        protected FacetAssembler $facetAssembler,
        protected IndexTables $tables
    ) {
    }

    /**
     * @return Facet[]
     */
    public function build(SearchQuery $query): array
    {
        $attributeIds = array_map(
            'strval',
            $this->attributeConfiguration->getFacetAttributeIds($query->getShopId())
        );

        if ($attributeIds === []) {
            return [];
        }

        $selection = $this->facetAssembler->getSelectionMap($query);
        $counts = [];

        foreach ($this->fetchRows($query, $attributeIds, $selection) as $attributeId => $rows) {
            foreach ($rows as $row) {
                $counts[(string) $attributeId][] = [
                    'valueId' => (string) $row['FOUN10VALUEID'],
                    'value' => (string) $row['FOUN10VALUE'],
                    'count' => (int) $row['FOUN10COUNT'],
                ];
            }
        }

        return $this->facetAssembler->assemble(
            $query,
            $attributeIds,
            $counts,
            fn (string $attributeId, string $valueId, string $mode): string
                => $this->fetchValueLabel($attributeId, $valueId, $query, $mode)
        );
    }

    /**
     * The counted values of every facet, keyed by attribute ID.
     *
     * A facet has to be counted with its *own* selection removed, or picking
     * "red" would show every other colour at zero and the customer could never
     * widen the choice again. That is why this used to be one query per facet.
     *
     * It only has to be, though, for a facet that actually carries a selection.
     * Every unselected one is counted against the very same condition set, so
     * they all fit in a single grouped query - and on the page customers see
     * most, nothing is selected at all. Measured on the local catalogue: eight
     * separate queries took 2.7s where one grouped query takes 0.4s.
     *
     * No LIMIT in SQL: the limit is per facet, which one grouped query cannot
     * express. It is applied per attribute afterwards instead, which is
     * affordable because the whole shop holds only a few hundred distinct facet
     * values - the rows coming back are counted in hundreds, not thousands.
     *
     * @param string[]                $attributeIds
     * @param array<string, string[]> $selection    Selected value IDs per attribute
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function fetchRows(SearchQuery $query, array $attributeIds, array $selection): array
    {
        $shared = [];
        $rows = [];

        foreach ($attributeIds as $attributeId) {
            if (($selection[$attributeId] ?? []) === []) {
                $shared[] = $attributeId;

                continue;
            }

            // Carries a selection, so it needs its own condition set. The
            // query answers keyed by attribute even for one of them, so the
            // rows have to be unwrapped rather than stored as the map.
            $grouped = $this->fetchRowsFor($query, [$attributeId], $attributeId);
            $rows[$attributeId] = $grouped[$attributeId] ?? [];
        }

        if ($shared !== []) {
            foreach ($this->fetchRowsFor($query, $shared, null) as $attributeId => $attributeRows) {
                $rows[$attributeId] = $attributeRows;
            }
        }

        return $rows;
    }

    /**
     * One counting query, grouped by attribute so it can answer several at once.
     *
     * @param string[] $attributeIds
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function fetchRowsFor(SearchQuery $query, array $attributeIds, ?string $excludeAttributeId): array
    {
        if ($attributeIds === []) {
            return [];
        }

        $database = DatabaseProvider::getDb();
        $conditions = $this->conditionBuilder->build($query, $excludeAttributeId);
        $quotedIds = implode(', ', $database->quoteArray($attributeIds));

        $sql = $this->hasGroupCounts($query) && !$this->hasCombiningFilter($query, $excludeAttributeId)
            ? $this->buildGroupCountSql($conditions->where, $quotedIds, $query->getShopId())
            : $this->buildVariantCountSql($conditions->where, $quotedIds, $query->getShopId());

        $grouped = [];

        foreach (DatabaseHelper::fetchAll($sql, $conditions->parameters) as $row) {
            $grouped[(string) $row['FOUN10ATTRID']][] = $row;
        }

        return $grouped;
    }

    /**
     * Counting query against the product level table.
     *
     * The matched variants are collapsed to their products first, in a derived
     * table, and only those products are joined to their values. That is the
     * whole trick: the join then runs over hundreds of rows instead of tens of
     * thousands.
     *
     * **IGNORE INDEX (FOUN10GROUP) is not decoration.** SELECT DISTINCT on
     * FOUN10GROUPID invites an optimiser to reach the table through that key -
     * MySQL 5.7 declines, MariaDB 11.8 accepts, and then looks up every
     * candidate row individually. Measured on the staging server, search "bh"
     * on 104k documents:
     *
     *   as written, with the key available   8,763 ms   50,926 disk page reads
     *   with the key ignored                 1,035 ms    7,852 disk page reads
     *
     * Blocking the split, disabling derived_merge, STRAIGHT_JOIN and a
     * temporary table were all measured too and all stayed at 8-9 seconds, so
     * this is the access path and nothing else. The hint removes the bad option
     * rather than dictating a good one - FORCE INDEX (FOUN10SCOPE) measured
     * identically and would also apply where another key is genuinely better.
     * On MySQL it changes nothing (156 ms either way), which is what makes it
     * safe to carry for both servers.
     *
     * **The join names OXSHOPID even though the table belongs to one shop.**
     * The key is (OXSHOPID, FOUN10LANGID, FOUN10GROUPID, ...), so without an
     * equality on its first column the optimiser cannot use it to look a
     * product up - it scans the whole table instead and probes the matched set
     * per row. Measured locally on "slip", 658 products out of 106 values:
     *
     *   join without the shop   479 ms
     *   join with the shop       88 ms
     *
     * The matched products on their own cost 75 ms of that, so with the shop
     * named the counting is very nearly free. Dropping COUNT or the ORDER BY
     * was measured too and changes nothing - the join is the cost, not the
     * aggregation.
     */
    protected function buildGroupCountSql(string $where, string $quotedAttributeIds, int $shopId): string
    {
        return '
            SELECT
                g.FOUN10ATTRID,
                g.FOUN10VALUEID,
                g.FOUN10VALUE,
                COUNT(*) AS FOUN10COUNT
            FROM (
                SELECT DISTINCT i.FOUN10GROUPID
                FROM ' . $this->tables->index($shopId) . ' AS i IGNORE INDEX (FOUN10GROUP)
                WHERE ' . $where . '
            ) AS m
            INNER JOIN ' . $this->tables->attributeGroup($shopId) . ' AS g
                ON g.OXSHOPID = ' . $shopId . '
                AND g.FOUN10LANGID = :langId
                AND g.FOUN10GROUPID = m.FOUN10GROUPID
            WHERE g.FOUN10ATTRID IN (' . $quotedAttributeIds . ')
            GROUP BY g.FOUN10ATTRID, g.FOUN10VALUEID, g.FOUN10VALUE
            ORDER BY g.FOUN10ATTRID ASC, FOUN10COUNT DESC, g.FOUN10VALUE ASC';
    }

    /**
     * The same counts read straight from the variant rows.
     *
     * Slower by a factor of three to seven, and exact for every combination of
     * selected facets. Used while the product level table is still empty -
     * after the migration and before the first rebuild - so a shop in that
     * window keeps a working sidebar instead of an empty one.
     */
    protected function buildVariantCountSql(string $where, string $quotedAttributeIds, int $shopId): string
    {
        return '
            SELECT
                a.FOUN10ATTRID,
                a.FOUN10VALUEID,
                a.FOUN10VALUE,
                COUNT(DISTINCT i.FOUN10GROUPID) AS FOUN10COUNT
            FROM ' . $this->tables->index($shopId) . ' AS i
            INNER JOIN ' . $this->tables->attribute($shopId) . ' AS a
                ON a.FOUN10INDEXID = i.OXID
            WHERE ' . $where . '
                AND a.FOUN10ATTRID IN (' . $quotedAttributeIds . ')
            GROUP BY a.FOUN10ATTRID, a.FOUN10VALUEID, a.FOUN10VALUE
            ORDER BY a.FOUN10ATTRID ASC, FOUN10COUNT DESC, a.FOUN10VALUE ASC';
    }

    /**
     * Is another facet already narrowing this query?
     *
     * This decides which counting table may answer, and it is a correctness
     * question rather than a performance one. Filtering happens per variant -
     * a product only survives "cup C" if one of its variants actually is a
     * cup C - while the product level table counts per product. With nothing
     * selected the two agree. With something selected they stop agreeing: a
     * product whose one variant is a cup C and whose other variant has band 75
     * counts for both values, so the sidebar offers band 75, and picking it
     * finds nothing, because no single variant is both.
     *
     * Measured on the real case that found this (search "ulla zoe", cups B/C/D
     * selected): the product level count offered band 75 on 2 products, the
     * variant level count on 0 - and 0 was the truth.
     *
     * So: as long as the query has no facet filter of its own, count products,
     * which is the fast path and the one an unfiltered listing uses. As soon as
     * one is active, count variants - and by then the result set is narrowed
     * enough that it costs nothing. Measured on "bh" with a colour selected:
     * 235 ms per product against 227 ms per variant, the difference being noise
     * and the two extra rows the product level count returned being exactly the
     * combinations that lead nowhere.
     */
    protected function hasCombiningFilter(SearchQuery $query, ?string $excludeAttributeId): bool
    {
        foreach ($query->getFilters() as $filter) {
            if ($filter->isEmpty() || $filter->getAttributeId() === $excludeAttributeId) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Does this scope have product level rows yet?
     *
     * A missing table means the migration has not run, an empty one means the
     * index has not been rebuilt since it did. Both answer the same way: fall
     * back to counting variants rather than showing a sidebar of zeros.
     */
    protected function hasGroupCounts(SearchQuery $query): bool
    {
        $key = $query->getShopId() . '_' . $query->getLangId();

        if (isset($this->hasGroupCounts[$key])) {
            return $this->hasGroupCounts[$key];
        }

        $sql = '
            SELECT 1
            FROM ' . $this->tables->attributeGroup($query->getShopId()) . '
            WHERE FOUN10LANGID = :langId
            LIMIT 1';

        try {
            $exists = (bool) DatabaseProvider::getDb()->getOne($sql, [
                ':langId' => $query->getLangId(),
            ]);
        } catch (Throwable $exception) {
            $exists = false;
        }

        return $this->hasGroupCounts[$key] = $exists;
    }

    /**
     * Label of a value that is no longer in the result set. Read from the
     * index rather than kept in the URL, so filter links stay short.
     *
     * Looked up by attribute as well as by value, which is what lets both
     * tables answer it from an index instead of scanning for the value ID.
     */
    protected function fetchValueLabel(
        string $attributeId,
        string $valueId,
        SearchQuery $query,
        string $mode = FacetDisplay::MODE_DEFAULT
    ): string {
        $table = $this->hasGroupCounts($query)
            ? $this->tables->attributeGroup($query->getShopId())
            : $this->tables->attribute($query->getShopId());

        $sql = '
            SELECT FOUN10VALUE
            FROM ' . $table . '
            WHERE FOUN10LANGID = :langId
                AND FOUN10ATTRID = :attributeId
                AND FOUN10VALUEID = :valueId
            LIMIT 1';

        $value = (string) DatabaseProvider::getDb()->getOne($sql, [
            ':langId' => $query->getLangId(),
            ':attributeId' => $attributeId,
            ':valueId' => $valueId,
        ]);

        return FacetDisplay::isColor($mode) ? ColorValue::parse($value)->getName() : trim($value);
    }
}
