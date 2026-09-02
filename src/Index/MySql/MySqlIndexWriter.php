<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index\MySql;

use foun10\EasySearch\Index\IndexDocument;
use foun10\EasySearch\Index\IndexWriterInterface;
use foun10\EasySearch\Index\RebuildResult;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\TableViewNameGenerator;
use RuntimeException;
use Throwable;

/**
 * Writes index documents into the MySQL tables.
 *
 * One strategy, because there is a table per shop: fill a shadow table and swap
 * it in with a single RENAME. Search traffic keeps hitting the old index until
 * that moment and never sees a partially built one - and that now holds for a
 * scoped rebuild too, which used to have to replace rows in place because a
 * rename would have taken the other shops with it.
 *
 * Tables are created when a rebuild first writes to them; nothing migrates them
 * into existence - see TableSchema.
 *
 * The shadow table is created without its fulltext indexes and they are added
 * back just before the swap, one statement each. Bulk loading into a live
 * fulltext index is roughly an order of magnitude slower, and MySQL 5.7 refuses
 * to create two fulltext indexes in one ALTER.
 */
class MySqlIndexWriter implements IndexWriterInterface
{
    /**
     * Holds one chosen label per value while the product level table is being
     * derived. Temporary, so it never survives the run that made it.
     */
    protected const TABLE_LABEL_CHOICE = 'foun10easysearchlabelchoice_tmp';

    /**
     * Share of the live assignments the source has to still hold for a category
     * rebuild to publish.
     *
     * Guards against rebuilding while the ERP import has oxobject2category
     * truncated - the source reads as empty or nearly so, and publishing that
     * would blank every category page in the shop. Half is generous: a real
     * catalogue does not lose half its category assignments between two runs,
     * and anything that legitimately does can pass $force.
     */
    protected const MIN_RETAINED_RATIO = 0.5;

    protected bool $started = false;

    /**
     * Shops this run has written to. Only these are swapped at commit, so a
     * rebuild of one shop leaves the others exactly as they were.
     *
     * @var array<int, true>
     */
    protected array $touchedShops = [];

    public function __construct(
        protected IndexTables $tables
    ) {
    }

    public function begin(array $scopes = []): void
    {
        if ($this->started) {
            throw new RuntimeException('Index write run already started');
        }

        $this->started = true;
        $this->touchedShops = [];

        foreach ($this->getShopIds($scopes) as $shopId) {
            $this->createShadowTables($shopId);
        }
    }

    public function resume(array $scopes = []): void
    {
        // The shadow tables an earlier request created are sitting in the
        // database under a name this object can derive again, so resuming is
        // only a matter of allowing writes and remembering what to swap.
        $this->started = true;

        foreach ($this->getShopIds($scopes) as $shopId) {
            $this->touchedShops[$shopId] = true;
        }
    }

    /**
     * Nothing to clear any more: a rebuild fills a shadow table and the live one
     * keeps serving until the swap.
     *
     * The step stays in the interface because the browser driven rebuild has a
     * phase for it, and because a run that died before its swap leaves a shadow
     * table that must not be filled twice. Answering zero ends the caller's
     * loop after one tick.
     */
    public function clearScopeBatch(int $shopId, int $langId, int $limit): int
    {
        $this->createShadowTables($shopId);

        return 0;
    }

    public function write(array $documents): void
    {
        if (!$this->started) {
            throw new RuntimeException('Index write run not started');
        }

        if ($documents === []) {
            return;
        }

        foreach ($this->groupByShop($documents) as $shopId => $shopDocuments) {
            $this->createShadowTables($shopId);

            $this->insertDocuments($shopId, $shopDocuments);
            $this->insertAttributes($shopId, $shopDocuments);
        }
    }

    public function commit(): void
    {
        if (!$this->started) {
            throw new RuntimeException('Index write run not started');
        }

        foreach (array_keys($this->touchedShops) as $shopId) {
            // Derived from the rows this run just wrote, before anything goes
            // live, so the counts a customer sees can never describe a
            // different index than the results do.
            $this->fillAttributeGroups($shopId);
            $this->addFulltextIndexes($shopId);
            $this->swapTables($shopId);
        }

        $this->reset();
    }

    public function rollback(): void
    {
        foreach (array_keys($this->touchedShops) as $shopId) {
            $this->dropShadowTables($shopId);
        }

        $this->reset();
    }

    /**
     * Removes a single article from the live index, for incremental updates out
     * of the ERP import rather than a full rebuild.
     */
    public function delete(string $articleId, int $shopId, int $langId): void
    {
        $indexTable = $this->tables->index($shopId);

        if (!$this->tables->exists($indexTable)) {
            return;
        }

        $parameters = [':articleId' => $articleId, ':langId' => $langId];

        $indexIds = $this->fetchColumn(
            'SELECT OXID FROM ' . $indexTable . '
             WHERE FOUN10ARTICLEID = :articleId AND FOUN10LANGID = :langId',
            $parameters
        );

        if ($indexIds !== []) {
            $quotedIds = $this->quoteList($indexIds);
            $this->execute(
                'DELETE FROM ' . $this->tables->attribute($shopId) . " WHERE FOUN10INDEXID IN ({$quotedIds})"
            );
        }

        $this->execute(
            'DELETE FROM ' . $indexTable . '
             WHERE FOUN10ARTICLEID = :articleId AND FOUN10LANGID = :langId',
            $parameters
        );
    }

    /**
     * Rebuilds one scope's category assignments in a single statement.
     *
     * Derived in SQL rather than walked in PHP: the assignments are a join
     * away, and the whole point of this being its own operation is that it
     * finishes in seconds and can run on a schedule between full rebuilds.
     *
     * Restricted to groups that are actually indexed, so the table cannot grow
     * assignments for products the search would never return anyway.
     *
     * Delete plus insert inside a transaction rather than a swap: the language
     * scopes share the table, and a reader should see one state or the other.
     */
    public function rebuildCategories(int $shopId, int $langId, bool $force = false): RebuildResult
    {
        $indexTable = $this->tables->index($shopId);

        if (!$this->tables->exists($indexTable)) {
            // Nothing indexed for this shop, so there is nothing to derive and
            // nothing to protect.
            return RebuildResult::published('category assignments', 0, 0);
        }

        $categoryTable = $this->tables->ensure(TableSchema::CATEGORY, $shopId);

        // Interpolated, not bound: both are ints by signature, and the
        // statements below repeat them several times - which named parameters
        // do not survive reliably through OXID's database layer.
        $scope = "FOUN10LANGID = {$langId}";
        $source = $this->getCategorySourceSql($shopId, $langId);

        $available = $this->fetchCount("SELECT COUNT(*) FROM ({$source}) AS available");
        $previous = $this->fetchCount("SELECT COUNT(*) FROM {$categoryTable} WHERE {$scope}");

        if (!$force && $this->isImplausible($available, $previous)) {
            return RebuildResult::skipped('category assignments', $available, $previous);
        }

        $this->startTransaction();

        try {
            $this->execute("DELETE FROM {$categoryTable} WHERE {$scope}");
            $this->execute(
                'INSERT INTO ' . $categoryTable . ' (
                    OXID, OXSHOPID, FOUN10LANGID, FOUN10GROUPID, FOUN10CATID
                 ) SELECT
                    MD5(CONCAT_WS("_", ' . $shopId . ', ' . $langId . ', groupId, catId)),
                    ' . $shopId . ', ' . $langId . ', groupId, catId
                 FROM (' . $source . ') AS assignments'
            );

            $written = $this->fetchCount("SELECT COUNT(*) FROM {$categoryTable} WHERE {$scope}");

            $this->commitTransaction();
        } catch (Throwable $exception) {
            $this->rollbackTransaction();

            throw $exception;
        }

        return RebuildResult::published('category assignments', $written, $previous);
    }

    /**
     * The assignments the shop currently holds for one scope.
     *
     * DISTINCT on the group first: the index carries a row per variant, and
     * joining the category table against all of them would multiply every
     * assignment by the number of variants before the deduplication.
     */
    protected function getCategorySourceSql(int $shopId, int $langId): string
    {
        $view = $this->getViewName('oxobject2category', $langId, $shopId);

        // The derived table is not called "groups": that is a reserved word
        // from MySQL 8.0.2 on (window frames), and the statement is a syntax
        // error on every server newer than 5.7.
        return 'SELECT DISTINCT grouped.FOUN10GROUPID AS groupId, o2c.OXCATNID AS catId
                FROM (
                    SELECT DISTINCT FOUN10GROUPID
                    FROM ' . $this->tables->index($shopId) . '
                    WHERE FOUN10LANGID = ' . $langId . '
                ) AS grouped
                JOIN ' . $view . ' AS o2c ON o2c.OXOBJECTID = grouped.FOUN10GROUPID';
    }

    /**
     * Whether the source looks like it was read mid import.
     *
     * Only ever refuses when something is already published - a first run on an
     * empty table has nothing to protect and must be allowed through, or the
     * index could never be built in the first place.
     */
    protected function isImplausible(int $available, int $previous): bool
    {
        if ($previous === 0) {
            return false;
        }

        return $available < (int) ceil($previous * self::MIN_RETAINED_RATIO);
    }

    /**
     * Collapses the variant level facet rows into one row per product.
     *
     * The sidebar counts products, the attribute table holds variants, and
     * collapsing 40,000 variant rows down to 600 products on every request is
     * what made facet counting the most expensive part of a search. Measured
     * against the variant level query: 11x on a full listing, 5.9x on a term.
     *
     * Only visible variants contribute. A sold out colour must not stay in the
     * colour filter, and FOUN10VISIBLE = 1 is the same gate every search query
     * applies.
     */
    protected function fillAttributeGroups(int $shopId): void
    {
        $target = $this->tables->shadow($this->tables->attributeGroup($shopId));
        $attributes = $this->tables->shadow($this->tables->attribute($shopId));
        $index = $this->tables->shadow($this->tables->index($shopId));

        $this->fillLabelChoice($attributes, $index);

        $this->execute(
            'INSERT INTO ' . $target . ' (
                OXID, OXSHOPID, FOUN10LANGID, FOUN10GROUPID, FOUN10ATTRID, FOUN10VALUEID, FOUN10VALUE
             )
             SELECT
                MD5(CONCAT_WS("_", a.OXSHOPID, a.FOUN10LANGID, a.FOUN10GROUPID, a.FOUN10ATTRID, a.FOUN10VALUEID)),
                a.OXSHOPID, a.FOUN10LANGID, a.FOUN10GROUPID, a.FOUN10ATTRID, a.FOUN10VALUEID,
                COALESCE(MIN(c.FOUN10VALUE), MIN(a.FOUN10VALUE))
             FROM ' . $attributes . ' AS a
             INNER JOIN ' . $index . ' AS i
                ON i.OXID = a.FOUN10INDEXID AND i.FOUN10VISIBLE = 1
             LEFT JOIN ' . self::TABLE_LABEL_CHOICE . ' AS c
                ON c.FOUN10LANGID = a.FOUN10LANGID
                AND c.FOUN10ATTRID = a.FOUN10ATTRID
                AND c.FOUN10VALUEID = a.FOUN10VALUEID
             GROUP BY a.OXSHOPID, a.FOUN10LANGID, a.FOUN10GROUPID, a.FOUN10ATTRID, a.FOUN10VALUEID'
        );
    }

    /**
     * One spelling per value: a readable one, and among equally readable ones
     * the one the catalogue uses most.
     *
     * A value ID is the hash of the *normalised* value, so rows sharing one can
     * still differ in spelling - "Anita" and "ANITA", "Gainsboro" and
     * "gainsboro" - and the collation treats those as equal, which leaves a
     * plain GROUP BY free to surface either. Frequency alone is not the answer
     * either: "ANITA" is the majority spelling here by 18,326 rows to 13,171,
     * and "Anita" is the one to put in front of a customer. So shape decides
     * first and frequency breaks the tie.
     *
     * Counted with BINARY, or the collation folds the spellings together before
     * they can be counted. GROUP_CONCAT then orders them and the first is
     * taken - its length limit does not matter, because only the front of the
     * list is ever read.
     *
     * A temporary table rather than a derived one: MySQL materialises a derived
     * table without an index, and this is joined against every attribute row.
     */
    protected function fillLabelChoice(string $attributeTable, string $indexTable): void
    {
        $this->execute('DROP TEMPORARY TABLE IF EXISTS ' . self::TABLE_LABEL_CHOICE);
        $this->execute(
            'CREATE TEMPORARY TABLE ' . self::TABLE_LABEL_CHOICE . ' (
                FOUN10LANGID tinyint NOT NULL,
                FOUN10ATTRID char(32) COLLATE latin1_general_ci NOT NULL,
                FOUN10VALUEID char(32) COLLATE latin1_general_ci NOT NULL,
                FOUN10VALUE varchar(255) NOT NULL,
                PRIMARY KEY (FOUN10LANGID, FOUN10ATTRID, FOUN10VALUEID)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->execute(
            'INSERT INTO ' . self::TABLE_LABEL_CHOICE . '
             SELECT
                FOUN10LANGID, FOUN10ATTRID, FOUN10VALUEID,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(FOUN10VALUE ORDER BY shouting ASC, used DESC, FOUN10VALUE ASC SEPARATOR 0x1F),
                    0x1F,
                    1
                )
             FROM (
                SELECT
                    a.FOUN10LANGID, a.FOUN10ATTRID, a.FOUN10VALUEID,
                    MIN(a.FOUN10VALUE) AS FOUN10VALUE,
                    COUNT(*) AS used,
                    BINARY MIN(a.FOUN10VALUE) = BINARY UPPER(MIN(a.FOUN10VALUE))
                        OR BINARY MIN(a.FOUN10VALUE) = BINARY LOWER(MIN(a.FOUN10VALUE)) AS shouting
                FROM ' . $attributeTable . ' AS a
                INNER JOIN ' . $indexTable . ' AS i
                    ON i.OXID = a.FOUN10INDEXID AND i.FOUN10VISIBLE = 1
                GROUP BY a.FOUN10LANGID, a.FOUN10ATTRID, a.FOUN10VALUEID, BINARY a.FOUN10VALUE
             ) AS spellings
             GROUP BY FOUN10LANGID, FOUN10ATTRID, FOUN10VALUEID'
        );
    }

    /**
     * @param IndexDocument[] $documents
     */
    protected function insertDocuments(int $shopId, array $documents): void
    {
        $values = [];

        foreach ($documents as $document) {
            $values[] = '(' . implode(', ', [
                $this->quote($document->getId()),
                $document->getShopId(),
                $document->getLangId(),
                $this->quote($document->getArticleId()),
                $this->quote($document->getParentId()),
                $this->quote($document->getGroupId()),
                $document->isVisible() ? 1 : 0,
                $this->quote($document->getTitle()),
                $this->quote($document->getArtNum()),
                $this->quote($document->getEan()),
                $this->quote($document->getMpn()),
                $this->quote($document->getBrand()),
                $this->quote($document->getManufacturerId()),
                $this->quote(implode(' %% ', $document->getCategoryPaths())),
                $this->quote($document->getSearchText()),
                $this->quote($document->getBoostText()),
                $document->getPrice(),
                $document->getStock(),
                $document->getSoldAmount(),
                $document->getInsertDate() !== null
                    ? $this->quote($document->getInsertDate())
                    : 'NULL',
            ]) . ')';
        }

        $sql = 'INSERT INTO ' . $this->tables->shadow($this->tables->index($shopId)) . ' (
                OXID, OXSHOPID, FOUN10LANGID, FOUN10ARTICLEID, FOUN10PARENTID, FOUN10GROUPID,
                FOUN10VISIBLE, FOUN10TITLE, FOUN10ARTNUM, FOUN10EAN, FOUN10MPN, FOUN10BRAND,
                FOUN10MANUFACTURERID,
                FOUN10CATEGORYPATHS, FOUN10EASYSEARCHTEXT, FOUN10EASYSEARCHTEXTBOOST,
                FOUN10PRICE, FOUN10STOCK, FOUN10SOLDAMOUNT, FOUN10INSERTDATE
            ) VALUES ' . implode(', ', $values);

        $this->execute($sql);
    }

    /**
     * @param IndexDocument[] $documents
     */
    protected function insertAttributes(int $shopId, array $documents): void
    {
        $values = [];

        foreach ($documents as $document) {
            $position = 0;

            foreach ($document->getAttributes() as $attribute) {
                $values[] = '(' . implode(', ', [
                    $this->quote(md5($document->getId() . $attribute['attributeId'] . $attribute['valueId'])),
                    $document->getShopId(),
                    $document->getLangId(),
                    $this->quote($document->getId()),
                    $this->quote($document->getGroupId()),
                    $this->quote($attribute['attributeId']),
                    $this->quote($attribute['valueId']),
                    $this->quote($attribute['value']),
                    $position++,
                ]) . ')';
            }
        }

        if ($values === []) {
            return;
        }

        // One article can carry the same attribute value through both its own
        // and its parent assignment, which would collide on the primary key.
        $sql = 'INSERT IGNORE INTO ' . $this->tables->shadow($this->tables->attribute($shopId)) . ' (
                OXID, OXSHOPID, FOUN10LANGID, FOUN10INDEXID, FOUN10GROUPID,
                FOUN10ATTRID, FOUN10VALUEID, FOUN10VALUE, FOUN10SORT
            ) VALUES ' . implode(', ', $values);

        $this->execute($sql);
    }

    /**
     * Shadow tables for one shop, replacing whatever a failed run left behind.
     *
     * The index table is created without its fulltext keys; commit() adds them
     * once the rows are in.
     */
    protected function createShadowTables(int $shopId): void
    {
        if (isset($this->touchedShops[$shopId])) {
            return;
        }

        $this->touchedShops[$shopId] = true;

        $this->tables->createShadow(TableSchema::INDEX, $shopId, false);
        $this->tables->createShadow(TableSchema::ATTRIBUTE, $shopId);
        $this->tables->createShadow(TableSchema::ATTRIBUTE_GROUP, $shopId);
    }

    /**
     * One statement per index, deliberately.
     *
     * MariaDB accepts both keys in a single ALTER; MySQL 5.7 answers "InnoDB
     * presently supports one FULLTEXT index creation at a time" and fails the
     * whole rebuild at its very last step - after the catalogue has already
     * been read.
     */
    protected function addFulltextIndexes(int $shopId): void
    {
        $table = $this->tables->shadow($this->tables->index($shopId));

        $this->execute('ALTER TABLE ' . $table . ' ADD FULLTEXT KEY FOUN10FT_SEARCHTEXT (FOUN10EASYSEARCHTEXT)');
        $this->execute('ALTER TABLE ' . $table . ' ADD FULLTEXT KEY FOUN10FT_BOOST (FOUN10EASYSEARCHTEXTBOOST)');
    }

    /**
     * Takes one shop's shadow tables live in a single statement, so its three
     * tables can never be live out of sync with each other.
     *
     * The live tables are created first if they do not exist yet: RENAME needs
     * both sides, and a shop being indexed for the first time has only the
     * shadow.
     */
    protected function swapTables(int $shopId): void
    {
        $renames = [];
        $retired = [];

        foreach ([TableSchema::INDEX, TableSchema::ATTRIBUTE, TableSchema::ATTRIBUTE_GROUP] as $table) {
            $live = $this->tables->ensure($table, $shopId);
            $shadow = $this->tables->shadow($live);
            $old = $this->tables->retired($live);

            $this->tables->drop($old);

            $renames[] = $live . ' TO ' . $old;
            $renames[] = $shadow . ' TO ' . $live;
            $retired[] = $old;
        }

        $this->execute('RENAME TABLE ' . implode(', ', $renames));

        foreach ($retired as $old) {
            $this->tables->drop($old);
        }

        $this->tables->forget();
    }

    protected function dropShadowTables(int $shopId): void
    {
        foreach (TableSchema::TABLES as $table) {
            $this->tables->drop($this->tables->shadow($this->tables->name($table, $shopId)));
        }
    }

    /**
     * Shops a run covers. An empty scope list means every shop the installation
     * has.
     *
     * @param array<int, array{shopId: int, langId: int}> $scopes
     *
     * @return int[]
     */
    protected function getShopIds(array $scopes): array
    {
        if ($scopes === []) {
            return $this->getAllShopIds();
        }

        $shopIds = [];

        foreach ($scopes as $scope) {
            $shopIds[(int) $scope['shopId']] = true;
        }

        return array_keys($shopIds);
    }

    /**
     * @param IndexDocument[] $documents
     *
     * @return array<int, IndexDocument[]>
     */
    protected function groupByShop(array $documents): array
    {
        $grouped = [];

        foreach ($documents as $document) {
            $grouped[$document->getShopId()][] = $document;
        }

        return $grouped;
    }

    protected function reset(): void
    {
        $this->started = false;
        $this->touchedShops = [];
        $this->tables->forget();
    }

    /*
     * The shop touch points, kept apart from the steps above.
     *
     * This class is one long argument about *order* - fill a shadow table,
     * derive from what was written, index it, swap it in one statement, drop
     * what it replaced - and none of that needs a database to be checked.
     * Statements leave through these; the tables they name come from the
     * injected IndexTables.
     */

    /**
     * @param array<string, mixed> $parameters
     */
    protected function execute(string $sql, array $parameters = []): void
    {
        DatabaseProvider::getDb()->execute($sql, $parameters);
    }

    protected function fetchCount(string $sql): int
    {
        return (int) DatabaseProvider::getDb()->getOne($sql);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return string[]
     */
    protected function fetchColumn(string $sql, array $parameters = []): array
    {
        return (array) DatabaseProvider::getDb()->getCol($sql, $parameters);
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

    protected function startTransaction(): void
    {
        DatabaseProvider::getDb()->startTransaction();
    }

    protected function commitTransaction(): void
    {
        DatabaseProvider::getDb()->commitTransaction();
    }

    protected function rollbackTransaction(): void
    {
        DatabaseProvider::getDb()->rollbackTransaction();
    }

    protected function getViewName(string $table, int $langId, int $shopId): string
    {
        return Registry::get(TableViewNameGenerator::class)
            ->getViewName($table, $langId, $shopId);
    }

    /**
     * @return int[]
     */
    protected function getAllShopIds(): array
    {
        return array_map('intval', (array) Registry::getConfig()->getShopIds());
    }
}
