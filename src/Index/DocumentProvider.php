<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\ColorGrouper;
use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Core\FacetDisplay;
use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Correction\Normalizer;
use Generator;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\TableViewNameGenerator;

/**
 * Reads the catalogue and turns it into IndexDocument objects.
 *
 * Streams through a generator rather than building an array: a full catalogue
 * across four subshops does not belong in memory at once, and the previous
 * Doofinder export needed a 4 GB memory_limit precisely because it did.
 *
 * Everything is scoped by explicit shop and language IDs and read through the
 * corresponding OXID views, so a reindex over all subshops runs in one process
 * without mutating global shop context.
 */
class DocumentProvider
{
    protected const BOOST_TEXT_MAX_LENGTH = 1024;

    /**
     * Per subshop article field overrides. Enterprise only - see
     * hasShopPriceOverrides().
     */
    protected const TABLE_SHOP_FIELDS = 'oxfield2shop';

    /**
     * @var array<string, array<string, string>> Category paths per shop/lang scope
     */
    protected array $categoryPathCache = [];

    /**
     * @var string[]|null
     */
    protected ?array $facetAttributeIds = null;

    /**
     * @var string[]|null
     */
    protected ?array $searchableAttributeIds = null;

    /**
     * Shop the current run is reading. The attribute configuration is per shop,
     * and the memoised lists must not leak from one scope into the next.
     */
    protected int $scopeShopId = 0;

    /**
     * @var string[]|null Attributes whose values are collapsed into colour groups
     */
    protected ?array $colorGroupedAttributeIds = null;

    /**
     * Null until the edition has been asked about once.
     */
    protected ?bool $shopPriceOverrides = null;

    public function __construct(
        protected Normalizer $normalizer,
        protected DiscountResolver $discountResolver,
        protected AttributeConfiguration $attributeConfiguration,
        protected VisibilityResolver $visibilityResolver,
        protected ColorGrouper $colorGrouper,
        protected ModuleSettings $moduleSettings
    ) {
    }

    /**
     * Number of articles that will be indexed for this scope. Used to drive
     * the progress bar of the reindex command.
     */
    public function countArticles(int $shopId, int $langId): int
    {
        $articleView = $this->getViewName('oxarticles', $langId, $shopId);

        $sql = "SELECT COUNT(*) FROM {$articleView} AS a WHERE " . $this->getArticleWhere();

        return $this->fetchCount($sql);
    }

    /**
     * @return Generator<int, IndexDocument>
     */
    public function provide(int $shopId, int $langId, int $batchSize = 500): Generator
    {
        $lastId = '';

        while (true) {
            $batch = $this->provideBatch($shopId, $langId, $lastId, $batchSize);

            if ($batch['documents'] === []) {
                break;
            }

            $lastId = $batch['lastId'];

            foreach ($batch['documents'] as $document) {
                yield $document;
            }
        }
    }

    /**
     * One batch of documents after $lastId, plus the cursor to continue from.
     *
     * Exposed separately from provide() so a run can be driven across several
     * HTTP requests: a web request cannot hold a full catalogue rebuild open,
     * so the admin reindex asks for one batch at a time and carries the cursor
     * between ticks.
     *
     * @return array{documents: IndexDocument[], lastId: string}
     */
    public function provideBatch(int $shopId, int $langId, string $lastId, int $batchSize): array
    {
        $this->setScope($shopId);

        $rows = $this->fetchArticleBatch($shopId, $langId, $lastId, $batchSize);

        if ($rows === []) {
            return ['documents' => [], 'lastId' => $lastId];
        }

        $articleIds = array_column($rows, 'OXID');
        $groupIds = array_unique(array_map(
            static fn (array $row): string => $row['OXPARENTID'] !== '' ? $row['OXPARENTID'] : $row['OXID'],
            $rows
        ));

        // Parent IDs are only read when their attributes are wanted: the join
        // over oxobject2attribute is the biggest one in the indexer, and asking
        // for rows that will be discarded is the one cost with no upside.
        $attributes = $this->fetchAttributes(
            $this->moduleSettings->useParentAttributes()
                ? array_merge($articleIds, $groupIds)
                : $articleIds,
            $shopId,
            $langId
        );
        $categoryIds = $this->fetchCategoryIds($groupIds, $shopId, $langId);
        $prices = $this->resolvePrices($rows, $categoryIds, $shopId, $langId);

        $documents = [];

        foreach ($rows as $row) {
            $documents[] = $this->createDocument($row, $shopId, $langId, $attributes, $categoryIds, $prices);
        }

        return [
            'documents' => $documents,
            'lastId' => (string) $rows[count($rows) - 1]['OXID'],
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $attributes
     * @param array<string, string[]>                         $categoryIds
     * @param array<string, float>                            $prices
     */
    protected function createDocument(
        array $row,
        int $shopId,
        int $langId,
        array $attributes,
        array $categoryIds,
        array $prices
    ): IndexDocument {
        $articleId = (string) $row['OXID'];
        $parentId = (string) ($row['OXPARENTID'] ?? '');
        $groupId = $parentId !== '' ? $parentId : $articleId;

        // A variant may inherit the attributes of its parent - size sits on the
        // variant, material usually on the parent. Whether it does is a setting,
        // because it depends on how the catalogue is written: an ERP that puts
        // the union of all variant values on the parent turns inheritance into
        // a lie, where a 75B claims every cup from B to F.
        $documentAttributes = $this->moduleSettings->useParentAttributes()
            ? array_merge($attributes[$groupId] ?? [], $attributes[$articleId] ?? [])
            : ($attributes[$articleId] ?? []);

        // Two different jobs: facet attributes become rows in the facet index,
        // searchable attributes become words in the fulltext blob.
        $facetAttributes = $this->toFacetValues(
            $this->filterByIds($documentAttributes, $this->getFacetAttributeIds())
        );
        $searchableAttributes = $this->getSearchableAttributeIds() === []
            ? $documentAttributes
            : $this->filterByIds($documentAttributes, $this->getSearchableAttributeIds());
        $categoryPaths = $this->toCategoryPaths($categoryIds[$groupId] ?? [], $shopId, $langId);

        $boostText = $this->normalizer->normalize(implode(' ', [
            $row['OXTITLE'] ?? '',
            $row['BRANDTITLE'] ?? '',
            $row['OXVARSELECT'] ?? '',
            $row['OXARTNUM'] ?? '',
            $row['OXEAN'] ?? '',
            $row['OXMPN'] ?? '',
        ]));

        $searchText = $this->normalizer->normalize(implode(' ', [
            $boostText,
            implode(' ', $categoryPaths),
            implode(' ', array_column($searchableAttributes, 'value')),
            $row['OXSHORTDESC'] ?? '',
            strip_tags((string) ($row['OXLONGDESC'] ?? '')),
        ]));

        return new IndexDocument(
            $this->buildDocumentId($articleId, $shopId, $langId),
            $shopId,
            $langId,
            $articleId,
            $parentId,
            $groupId,
            (string) ($row['OXTITLE'] ?? ''),
            (string) ($row['OXARTNUM'] ?? ''),
            (string) ($row['OXEAN'] ?? ''),
            (string) ($row['OXMPN'] ?? ''),
            (string) ($row['BRANDTITLE'] ?? ''),
            (string) ($row['OXMANUFACTURERID'] ?? ''),
            $categoryPaths,
            $facetAttributes,
            $searchText,
            mb_substr($boostText, 0, self::BOOST_TEXT_MAX_LENGTH),
            $prices[$articleId] ?? (float) ($row['OXPRICE'] ?? 0),
            (float) ($row['OXSTOCK'] ?? 0),
            (int) ($row['OXSOLDAMOUNT'] ?? 0),
            $this->toDateOrNull($row['OXINSERT'] ?? null),
            $this->visibilityResolver->isVisible($row),
            $categoryIds[$groupId] ?? []
        );
    }

    /**
     * Runs the batch through the discount resolver so the indexed price is the
     * one a customer actually pays, not the raw OXPRICE. Sorting and price
     * range filtering are only meaningful on the effective price.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string[]>          $categoryIds
     *
     * @return array<string, float>
     */
    protected function resolvePrices(array $rows, array $categoryIds, int $shopId, int $langId): array
    {
        $input = [];

        foreach ($rows as $row) {
            $articleId = (string) $row['OXID'];
            $parentId = (string) ($row['OXPARENTID'] ?? '');
            $groupId = $parentId !== '' ? $parentId : $articleId;

            $input[] = [
                'articleId' => $articleId,
                'parentId' => $parentId,
                'categoryIds' => $categoryIds[$groupId] ?? [],
                'price' => (float) ($row['OXPRICE'] ?? 0),
            ];
        }

        return $this->discountResolver->resolve($input, $shopId, $langId);
    }

    /**
     * @param string[] $ids
     *
     * @return string[]
     */
    protected function toCategoryPaths(array $ids, int $shopId, int $langId): array
    {
        $paths = $this->getCategoryPaths($shopId, $langId);
        $result = [];

        foreach ($ids as $id) {
            if (isset($paths[$id])) {
                $result[] = $paths[$id];
            }
        }

        return $result;
    }

    /**
     * Switches the run to a shop and drops the memoised lists of the previous
     * one.
     */
    protected function setScope(int $shopId): void
    {
        if ($this->scopeShopId === $shopId) {
            return;
        }

        $this->scopeShopId = $shopId;
        $this->facetAttributeIds = null;
        $this->searchableAttributeIds = null;
        $this->colorGroupedAttributeIds = null;
    }

    /**
     * Attributes offered as filters. Cached for the run - the setting is read
     * once per document otherwise.
     *
     * @return string[]
     */
    protected function getFacetAttributeIds(): array
    {
        if ($this->facetAttributeIds === null) {
            $this->facetAttributeIds = array_map('strval', $this->attributeConfiguration->getFacetAttributeIds($this->scopeShopId));
        }

        return $this->facetAttributeIds;
    }

    /**
     * The facet copy of an article's attributes: colour groups applied, and
     * every attribute/value pair carried once.
     *
     * Only this copy is touched. The searchable copy keeps the original names,
     * so a customer searching "bisque" or "Tomatencreme" still finds the
     * product even though the filter offers "Beige". A value the grouper
     * cannot read - no hex code in it - is left exactly as it came.
     *
     * The de-duplication is not only for grouping, which is why it runs even
     * when no attribute is grouped: a variant carrying two colours of one
     * group contributes that group once, and a variant that inherits its
     * parent's material while carrying it itself contributes it once. Without
     * that, the writer stores the pair twice and the facet count says two
     * products where there is one.
     *
     * @param array<int, array<string, mixed>> $attributes
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toFacetValues(array $attributes): array
    {
        if ($attributes === []) {
            return $attributes;
        }

        $groupedIds = $this->getColorGroupedAttributeIds();
        $result = [];
        $seen = [];

        foreach ($attributes as $attribute) {
            $attributeId = (string) $attribute['attributeId'];

            if (in_array($attributeId, $groupedIds, true)) {
                $grouped = $this->colorGrouper->group((string) $attribute['value']);

                if ($grouped !== null) {
                    $attribute['value'] = $grouped;
                    $attribute['valueId'] = $this->buildValueId($grouped);
                }
            }

            $key = $attributeId . '_' . $attribute['valueId'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $attribute;
        }

        return $result;
    }

    /**
     * Attributes configured as grouped colour tiles, for the shop being read.
     *
     * @return string[]
     */
    protected function getColorGroupedAttributeIds(): array
    {
        if ($this->colorGroupedAttributeIds !== null) {
            return $this->colorGroupedAttributeIds;
        }

        $ids = [];

        foreach ($this->attributeConfiguration->getDisplayModes($this->scopeShopId) as $attributeId => $mode) {
            if (FacetDisplay::isColorGrouped($mode)) {
                $ids[] = (string) $attributeId;
            }
        }

        return $this->colorGroupedAttributeIds = $ids;
    }

    /**
     * Attributes whose values feed the fulltext blob. Empty means all of them.
     *
     * @return string[]
     */
    protected function getSearchableAttributeIds(): array
    {
        if ($this->searchableAttributeIds === null) {
            $this->searchableAttributeIds = array_map('strval', $this->attributeConfiguration->getSearchableAttributeIds($this->scopeShopId));
        }

        return $this->searchableAttributeIds;
    }

    /**
     * Every attribute worth reading at all: the union of both lists. An empty
     * searchable list means no restriction, so nothing can be excluded.
     *
     * @return string[]|null Null when everything has to be read
     */
    protected function getWantedAttributeIds(): ?array
    {
        if ($this->getSearchableAttributeIds() === []) {
            return null;
        }

        return array_values(array_unique(array_merge(
            $this->getFacetAttributeIds(),
            $this->getSearchableAttributeIds()
        )));
    }

    /**
     * @param array<int, array<string, mixed>> $attributes
     * @param string[]                         $attributeIds
     *
     * @return array<int, array<string, mixed>>
     */
    protected function filterByIds(array $attributes, array $attributeIds): array
    {
        if ($attributeIds === []) {
            return [];
        }

        // No array_values(): the facet copy is rebuilt as a list by
        // toFacetValues(), and the searchable copy is only read by column.
        return array_filter(
            $attributes,
            static fn (array $attribute): bool => in_array($attribute['attributeId'], $attributeIds, true)
        );
    }

    /**
     * oxarticles.OXINSERT is a NOT NULL date defaulting to '0000-00-00', which
     * is truthy but not a date. Passing it into a datetime column fails the
     * whole insert under strict mode, so it becomes NULL here.
     */
    protected function toDateOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return $value;
    }

    /**
     * One article can appear in several shops and languages, so the primary
     * key has to include the scope.
     */
    protected function buildDocumentId(string $articleId, int $shopId, int $langId): string
    {
        return md5($articleId . '_' . $shopId . '_' . $langId);
    }

    /**
     * Keyset pagination on OXID rather than LIMIT offset,count.
     *
     * With 150k+ articles per scope a growing OFFSET makes the database walk
     * and discard every earlier row on each batch, so a full reindex degrades
     * quadratically. Seeking past the last ID keeps every batch the same cost.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchArticleBatch(int $shopId, int $langId, string $lastId, int $limit): array
    {
        $articleView = $this->getViewName('oxarticles', $langId, $shopId);
        $manufacturerView = $this->getViewName('oxmanufacturers', $langId, $shopId);
        $extendsView = $this->getViewName('oxartextends', $langId, $shopId);

        $overridden = $this->hasShopPriceOverrides();

        // Every text column falls back to the parent's, because a variant in
        // OXID carries none of them: title, descriptions, manufacturer, EAN and
        // MPN all live on the parent row and the shop's Article model resolves
        // them at runtime. Reading the variant row alone indexes a product
        // without its own name - see indexedFromTheParent() below.
        $inherited = static fn (string $column): string =>
            sprintf('COALESCE(NULLIF(a.%1$s, \'\'), p.%1$s, \'\') AS %1$s', $column);

        $manufacturerId = "COALESCE(NULLIF(a.OXMANUFACTURERID, ''), p.OXMANUFACTURERID, '')";

        $sql = "
            SELECT
                a.OXID,
                a.OXPARENTID,
                " . $inherited('OXTITLE') . ",
                a.OXVARSELECT,
                " . $inherited('OXARTNUM') . ",
                " . $inherited('OXEAN') . ",
                " . $inherited('OXMPN') . ",
                " . $inherited('OXSHORTDESC') . ",
                a.OXACTIVE,
                a.OXSOLDAMOUNT,
                a.OXINSERT,
                " . ($overridden
                    ? 'IF (f.OXPRICE IS NOT NULL AND f.OXPRICE != 0, f.OXPRICE, a.OXPRICE) AS OXPRICE'
                    : 'a.OXPRICE') . ",
                a.OXSTOCK,
                a.OXSTOCKFLAG,
                {$manufacturerId} AS OXMANUFACTURERID,
                m.OXTITLE AS BRANDTITLE,
                COALESCE(NULLIF(e.OXLONGDESC, ''), pe.OXLONGDESC, '') AS OXLONGDESC
            FROM {$articleView} AS a
            LEFT JOIN {$articleView} AS p ON p.OXID = a.OXPARENTID
            LEFT JOIN {$manufacturerView} AS m ON m.OXID = {$manufacturerId}
            LEFT JOIN {$extendsView} AS e ON e.OXID = a.OXID
            LEFT JOIN {$extendsView} AS pe ON pe.OXID = a.OXPARENTID
            " . ($overridden
                ? 'LEFT JOIN oxfield2shop AS f ON f.OXARTID = a.OXID AND f.OXSHOPID = :shopId'
                : '') . "
            WHERE " . $this->getArticleWhere() . "
                AND a.OXID > " . $this->quote($lastId) . "
            ORDER BY a.OXID ASC
            LIMIT {$limit}
        ";

        // The parameter goes only where the join that uses it went: a bound
        // name that appears in no statement is an error, not a spare.
        return $this->fetchRows($sql, $overridden ? [':shopId' => $shopId] : []);
    }

    /**
     * Whether this installation overrides article prices per subshop.
     *
     * oxfield2shop is an Enterprise table. In CE and PE it does not exist, and
     * joining it there fails the very first batch of a rebuild with "Table
     * 'oxfield2shop' doesn't exist" - so the join is only written when the
     * table is there to be joined. Asked once per request and remembered,
     * because a rebuild runs this query hundreds of times.
     */
    protected function hasShopPriceOverrides(): bool
    {
        return $this->shopPriceOverrides ??= $this->tableExists(self::TABLE_SHOP_FIELDS);
    }

    protected function tableExists(string $table): bool
    {
        return $this->fetchRows(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table',
            [':table' => $table]
        ) !== [];
    }

    /**
     * Index variants and standalone articles, never variant parents - a parent
     * has no own stock or size and would show up as a phantom hit.
     */
    protected function getArticleWhere(): string
    {
        return "a.OXACTIVE = 1 AND (a.OXPARENTID != '' OR a.OXVARCOUNT = 0)";
    }

    /**
     * @param string[] $objectIds
     *
     * @return array<string, array<int, array<string, mixed>>> Keyed by object ID
     */
    protected function fetchAttributes(array $objectIds, int $shopId, int $langId): array
    {
        if ($objectIds === []) {
            return [];
        }

        $object2AttributeView = $this->getViewName('oxobject2attribute', $langId, $shopId);
        $attributeView = $this->getViewName('oxattribute', $langId, $shopId);
        $quotedIds = $this->quoteList($objectIds);

        // Reading only the attributes that are actually used keeps the biggest
        // join in the whole indexer from returning rows nothing will look at.
        $wantedIds = $this->getWantedAttributeIds();
        $attributeCondition = $wantedIds === null || $wantedIds === []
            ? ''
            : ' AND o2a.OXATTRID IN (' . $this->quoteList($wantedIds) . ')';

        $sql = "
            SELECT
                o2a.OXOBJECTID,
                o2a.OXATTRID,
                o2a.OXVALUE,
                o2a.OXPOS,
                at.OXTITLE
            FROM {$object2AttributeView} AS o2a
            INNER JOIN {$attributeView} AS at ON at.OXID = o2a.OXATTRID
            WHERE o2a.OXOBJECTID IN ({$quotedIds})
                AND o2a.OXVALUE != ''
                {$attributeCondition}
            ORDER BY o2a.OXPOS ASC
        ";

        $result = [];

        foreach ($this->fetchRows($sql) as $row) {
            $value = trim((string) $row['OXVALUE']);

            if ($value === '') {
                continue;
            }

            $objectId = (string) $row['OXOBJECTID'];

            $result[$objectId][] = [
                'attributeId' => (string) $row['OXATTRID'],
                'title' => trim((string) $row['OXTITLE']),
                'valueId' => $this->buildValueId($value),
                'value' => $value,
            ];
        }

        return $result;
    }

    /**
     * Stable ID for a facet value, so filter URLs survive a reindex.
     */
    protected function buildValueId(string $value): string
    {
        return md5($this->normalizer->normalize($value));
    }

    /**
     * Category assignments of a batch.
     *
     * Returns raw category IDs rather than paths: the search text needs the
     * readable path, but discount matching needs the IDs, and fetching them
     * twice would be wasteful.
     *
     * @param string[] $groupIds
     *
     * @return array<string, string[]> Category IDs keyed by article ID
     */
    protected function fetchCategoryIds(array $groupIds, int $shopId, int $langId): array
    {
        if ($groupIds === []) {
            return [];
        }

        $object2CategoryView = $this->getViewName('oxobject2category', $langId, $shopId);
        $quotedIds = $this->quoteList($groupIds);

        $sql = "
            SELECT OXOBJECTID, OXCATNID
            FROM {$object2CategoryView}
            WHERE OXOBJECTID IN ({$quotedIds})
        ";

        $result = [];

        foreach ($this->fetchRows($sql) as $row) {
            $result[(string) $row['OXOBJECTID']][] = (string) $row['OXCATNID'];
        }

        return $result;
    }

    /**
     * Builds "Damen > Waesche > BHs" for every category, once per scope.
     *
     * The whole category tree is small enough to resolve in memory. Doing it
     * per product - as the Doofinder export did with a nested set query per
     * category - is what made that export slow.
     *
     * @return array<string, string>
     */
    protected function getCategoryPaths(int $shopId, int $langId): array
    {
        $cacheKey = $shopId . '_' . $langId;

        if (isset($this->categoryPathCache[$cacheKey])) {
            return $this->categoryPathCache[$cacheKey];
        }

        $categoryView = $this->getViewName('oxcategories', $langId, $shopId);

        $sql = "
            SELECT OXID, OXPARENTID, OXTITLE
            FROM {$categoryView}
            WHERE OXACTIVE = 1
        ";

        $rows = $this->fetchRows($sql);
        $categories = [];

        foreach ($rows as $row) {
            $categories[(string) $row['OXID']] = [
                'parentId' => (string) $row['OXPARENTID'],
                'title' => trim((string) $row['OXTITLE']),
            ];
        }

        $paths = [];

        foreach (array_keys($categories) as $categoryId) {
            $paths[$categoryId] = $this->buildCategoryPath($categoryId, $categories);
        }

        $this->categoryPathCache[$cacheKey] = $paths;

        return $paths;
    }

    /**
     * @param array<string, array{parentId: string, title: string}> $categories
     */
    protected function buildCategoryPath(string $categoryId, array $categories): string
    {
        $titles = [];
        $currentId = $categoryId;
        $guard = 0;

        // The guard protects against a cyclic parent reference in the data,
        // which would otherwise hang the whole reindex.
        while (isset($categories[$currentId]) && $guard < 20) {
            array_unshift($titles, $categories[$currentId]['title']);
            $currentId = $categories[$currentId]['parentId'];

            if ($currentId === '' || $currentId === 'oxrootid') {
                break;
            }

            $guard++;
        }

        return implode(' > ', array_filter($titles));
    }

    /*
     * The shop touch points, kept apart from the rules above.
     *
     * Everything else in this class - which article becomes which document,
     * what the search text is made of, how a category path is walked - is
     * ordinary logic over rows. These four are the only places where the rows
     * come from a database and the view names from a shop, and they are the
     * only ones a unit test has to stand in for.
     */

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchRows(string $sql, array $parameters = []): array
    {
        return DatabaseHelper::fetchAll($sql, $parameters);
    }

    protected function fetchCount(string $sql): int
    {
        return (int) DatabaseProvider::getDb()->getOne($sql);
    }

    protected function quote(string $value): string
    {
        return DatabaseProvider::getDb()->quote($value);
    }

    /**
     * @param string[] $values
     */
    protected function quoteList(array $values): string
    {
        return implode(', ', DatabaseProvider::getDb()->quoteArray($values));
    }

    protected function getViewName(string $table, int $langId, int $shopId): string
    {
        return Registry::get(TableViewNameGenerator::class)
            ->getViewName($table, $langId, $shopId);
    }
}
