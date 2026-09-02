<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index\MySql;

use InvalidArgumentException;

/**
 * What the index tables look like, in code rather than in a migration.
 *
 * These four tables hold nothing a merchant entered and nothing the shop
 * cannot produce again: every row is derived from the catalogue by a rebuild.
 * That is what makes them the writer's business rather than the schema's. They
 * are created when a rebuild needs them, they exist once per subshop, and a
 * shop that has never been indexed simply has none - which the engine already
 * reports as "not available", so the shop serves its own search until the first
 * run.
 *
 * The practical gain is that adding a shop, or changing a column, needs no
 * migration that knows how many shops exist. Every rebuild builds its shadow
 * table from the definition below, so a schema change travels with the code and
 * lands on the next reindex.
 *
 * The contract that comes with it: **a new column is only there after a
 * rebuild.** Under migrations the column appeared immediately and filled up
 * later; here both arrive together. Anything reading a column added in the same
 * release has to tolerate a shop that has not been rebuilt yet.
 *
 * Editorial tables - foun10easysearchattribute, foun10easysearchattributetitle,
 * foun10easysearchsynonym - stay in migrations, because losing one loses work a
 * merchant did.
 *
 * Every char(32) column is latin1_general_ci to match OXID's own ID columns.
 * Joining char(32) across two collations makes MariaDB convert every row and
 * abandon the index - measured as a rebuild running past 460 seconds instead of
 * finishing in seconds.
 */
class TableSchema
{
    public const INDEX = 'foun10easysearchindex';
    public const ATTRIBUTE = 'foun10easysearchindexattribute';
    public const ATTRIBUTE_GROUP = 'foun10easysearchindexattributegroup';
    public const CATEGORY = 'foun10easysearchindexcategory';

    public const TABLES = [
        self::INDEX,
        self::ATTRIBUTE,
        self::ATTRIBUTE_GROUP,
        self::CATEGORY,
    ];

    protected const ID = 'char(32) CHARACTER SET latin1 COLLATE latin1_general_ci';

    /**
     * DDL for one table under a given name.
     *
     * The fulltext keys are optional because a rebuild adds them after loading:
     * bulk loading into a live fulltext index is roughly an order of magnitude
     * slower than building the index once at the end.
     */
    public function getCreateStatement(string $table, string $name, bool $withFulltext = true): string
    {
        switch ($table) {
            case self::INDEX:
                return $this->getIndexStatement($name, $withFulltext);

            case self::ATTRIBUTE:
                return $this->getAttributeStatement($name);

            case self::ATTRIBUTE_GROUP:
                return $this->getAttributeGroupStatement($name);

            case self::CATEGORY:
                return $this->getCategoryStatement($name);
        }

        throw new InvalidArgumentException(sprintf('Unknown index table "%s"', $table));
    }

    /**
     * One row per variant, or per article without variants.
     */
    protected function getIndexStatement(string $name, bool $withFulltext): string
    {
        $fulltext = $withFulltext
            ? ',
                FULLTEXT KEY FOUN10FT_SEARCHTEXT (FOUN10EASYSEARCHTEXT),
                FULLTEXT KEY FOUN10FT_BOOST (FOUN10EASYSEARCHTEXTBOOST)'
            : '';

        return 'CREATE TABLE IF NOT EXISTS ' . $name . ' (
                OXID ' . self::ID . ' NOT NULL,
                OXSHOPID int(11) NOT NULL DEFAULT 1,
                FOUN10LANGID tinyint(4) NOT NULL DEFAULT 0,
                FOUN10ARTICLEID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10PARENTID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10GROUPID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10VISIBLE tinyint(1) NOT NULL DEFAULT 1,
                FOUN10TITLE varchar(255) NOT NULL DEFAULT "",
                FOUN10ARTNUM varchar(255) NOT NULL DEFAULT "",
                FOUN10EAN varchar(255) NOT NULL DEFAULT "",
                FOUN10MPN varchar(255) NOT NULL DEFAULT "",
                FOUN10BRAND varchar(255) NOT NULL DEFAULT "",
                FOUN10MANUFACTURERID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10CATEGORYPATHS text,
                FOUN10EASYSEARCHTEXT mediumtext,
                FOUN10EASYSEARCHTEXTBOOST varchar(1024) NOT NULL DEFAULT "",
                FOUN10PRICE decimal(9,2) NOT NULL DEFAULT 0.00,
                FOUN10STOCK double NOT NULL DEFAULT 0,
                FOUN10SOLDAMOUNT int(11) NOT NULL DEFAULT 0,
                FOUN10INSERTDATE datetime DEFAULT NULL,
                OXTIMESTAMP timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (OXID),
                KEY FOUN10SCOPE (OXSHOPID, FOUN10LANGID, FOUN10VISIBLE),
                KEY FOUN10GROUP (OXSHOPID, FOUN10LANGID, FOUN10GROUPID),
                KEY FOUN10ARTICLE (FOUN10ARTICLEID),
                KEY FOUN10MANUFACTURER (OXSHOPID, FOUN10LANGID, FOUN10MANUFACTURERID)' . $fulltext . '
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /**
     * Facet values per variant - what a filter matches against.
     */
    protected function getAttributeStatement(string $name): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . $name . ' (
                OXID ' . self::ID . ' NOT NULL,
                OXSHOPID int(11) NOT NULL DEFAULT 1,
                FOUN10LANGID tinyint(4) NOT NULL DEFAULT 0,
                FOUN10INDEXID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10GROUPID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10ATTRID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10VALUEID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10VALUE varchar(255) NOT NULL DEFAULT "",
                FOUN10SORT int(11) NOT NULL DEFAULT 0,
                OXTIMESTAMP timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (OXID),
                KEY FOUN10FACET (OXSHOPID, FOUN10LANGID, FOUN10ATTRID, FOUN10VALUEID),
                KEY FOUN10INDEX (FOUN10INDEXID),
                KEY FOUN10GROUP (FOUN10GROUPID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /**
     * The same values collapsed to one row per product - what the sidebar
     * counts. The scope key leads with the product, which is what the counting
     * query joins on.
     */
    protected function getAttributeGroupStatement(string $name): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . $name . ' (
                OXID ' . self::ID . ' NOT NULL,
                OXSHOPID int(11) NOT NULL DEFAULT 1,
                FOUN10LANGID tinyint(4) NOT NULL DEFAULT 0,
                FOUN10GROUPID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10ATTRID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10VALUEID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10VALUE varchar(255) NOT NULL DEFAULT "",
                OXTIMESTAMP timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (OXID),
                KEY FOUN10SCOPE (OXSHOPID, FOUN10LANGID, FOUN10GROUPID, FOUN10ATTRID, FOUN10VALUEID),
                KEY FOUN10FACET (OXSHOPID, FOUN10LANGID, FOUN10ATTRID, FOUN10VALUEID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /**
     * Category assignments per product.
     */
    protected function getCategoryStatement(string $name): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . $name . ' (
                OXID ' . self::ID . ' NOT NULL,
                OXSHOPID int(11) NOT NULL DEFAULT 1,
                FOUN10LANGID tinyint(4) NOT NULL DEFAULT 0,
                FOUN10GROUPID ' . self::ID . ' NOT NULL DEFAULT "",
                FOUN10CATID ' . self::ID . ' NOT NULL DEFAULT "",
                OXTIMESTAMP timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (OXID),
                KEY FOUN10CATLOOKUP (OXSHOPID, FOUN10LANGID, FOUN10CATID, FOUN10GROUPID),
                KEY FOUN10GROUP (FOUN10GROUPID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
}
