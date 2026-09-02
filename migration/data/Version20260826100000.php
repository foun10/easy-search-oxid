<?php

declare(strict_types=1);

namespace foun10\EasySearch\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * All tables the foun10EasySearch module needs.
 *
 * One migration rather than the five this grew from: the module has never been
 * deployed anywhere, so there is no installation whose history has to be
 * preserved, and a first release reads better as one schema than as a diary of
 * how it was written.
 *
 * **Only tables that cannot be rebuilt are here.** The index itself is not:
 * `foun10easysearchindex_s<n>`, `foun10easysearchindexattribute_s<n>`,
 * `foun10easysearchindexattributegroup_s<n>` and `foun10easysearchindexcategory_s<n>`
 * are created per subshop by the rebuild that fills them - see TableSchema and
 * IndexTables. Derived data whose shape follows the code that writes it has no
 * business in a migration: a new column would otherwise appear at deploy time
 * and fill up an hour later, and a shop that was never indexed would carry
 * empty tables pretending an index exists.
 *
 * What is left is the three kinds a rebuild cannot recreate:
 *
 *  - the correction dictionary, derived from the index but shared across shops
 *    and written by DictionaryBuilder, which does not create it;
 *  - editorial configuration a merchant enters in the admin - which attributes
 *    filter, what they are called, which words mean the same thing. It lives in
 *    tables rather than in module settings because settings are exported to
 *    var/configuration and pushed back by oe:module:deploy-configurations on
 *    every release, which would silently overwrite whatever was arranged in the
 *    backend;
 *  - the search log, which is neither derived nor editorial: it records what
 *    customers typed, and nothing can reconstruct that.
 *
 * Every char(32) ID column carries latin1_general_ci to match OXID's own
 * tables. Mixing collations makes MySQL convert every row of a join and
 * abandon the index - measured as minutes instead of seconds on a 4.3M row
 * source, and as a 250s facet query when only half the columns had been
 * converted.
 */
final class Version20260826100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables for the foun10EasySearch module: dictionary, editorial configuration, search log';
    }

    public function up(Schema $schema): void
    {
        // Dictionary for typo tolerance and for the completions in the suggest
        // box. Derived from the finished index, but not per shop - the shop is
        // a column here - so the rebuild fills it and this creates it.
        //
        // FOUN10BUCKET plus FOUN10LENGTH narrow the candidate set before the
        // Damerau-Levenshtein distance is calculated in PHP; FOUN10PHONETIC is
        // the second way in, for a word misspelled beyond edit distance.
        $this->addSql("
            CREATE TABLE IF NOT EXISTS foun10easysearchdictionary (
                OXID char(32) COLLATE latin1_general_ci not null,
                OXSHOPID int default 1 not null,
                FOUN10LANGID tinyint default 0 not null,
                FOUN10TERM varchar(64) default '' not null,
                FOUN10TERMRAW varchar(64) default '' not null,
                FOUN10BUCKET varchar(8) default '' not null,
                FOUN10PHONETIC varchar(32) default '' not null,
                FOUN10PARTS varchar(255) default '' not null,
                FOUN10LENGTH tinyint default 0 not null,
                FOUN10FREQUENCY int default 0 not null,
                FOUN10SOURCE varchar(32) default '' not null,
                OXTIMESTAMP timestamp default CURRENT_TIMESTAMP not null on update CURRENT_TIMESTAMP,
                PRIMARY KEY (OXID),
                UNIQUE KEY FOUN10TERM (OXSHOPID, FOUN10LANGID, FOUN10TERM),
                KEY FOUN10CANDIDATE (OXSHOPID, FOUN10LANGID, FOUN10BUCKET, FOUN10LENGTH),
                KEY FOUN10PHONETIC (OXSHOPID, FOUN10LANGID, FOUN10PHONETIC),
                KEY FOUN10PREFIX (OXSHOPID, FOUN10LANGID, FOUN10TERM, FOUN10FREQUENCY)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Which attributes filter, which are searchable, in what order and how
        // they are rendered. Language neutral on purpose - the label is not,
        // and lives in the table below.
        $this->addSql("
            CREATE TABLE IF NOT EXISTS foun10easysearchattribute (
                OXID char(32) COLLATE latin1_general_ci not null,
                OXSHOPID int default 1 not null,
                FOUN10ATTRID char(32) COLLATE latin1_general_ci default '' not null,
                FOUN10FACET tinyint(1) default 0 not null,
                FOUN10EASYSEARCHABLE tinyint(1) default 0 not null,
                FOUN10DISPLAY varchar(32) default 'default' not null,
                FOUN10SORT int default 0 not null,
                OXTIMESTAMP timestamp default CURRENT_TIMESTAMP not null on update CURRENT_TIMESTAMP,
                PRIMARY KEY (OXID),
                UNIQUE KEY FOUN10SHOPATTR (OXSHOPID, FOUN10ATTRID),
                KEY FOUN10FACETORDER (OXSHOPID, FOUN10FACET, FOUN10SORT)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Customer facing attribute label per language. Absent when nothing was
        // entered, in which case the attribute's own title is shown - which is
        // an ERP field like "Farbcode_HEX" and usually why somebody enters one.
        $this->addSql("
            CREATE TABLE IF NOT EXISTS foun10easysearchattributetitle (
                OXID char(32) COLLATE latin1_general_ci not null,
                OXSHOPID int default 1 not null,
                FOUN10ATTRID char(32) COLLATE latin1_general_ci default '' not null,
                FOUN10LANGID tinyint default 0 not null,
                FOUN10TITLE varchar(255) default '' not null,
                OXTIMESTAMP timestamp default CURRENT_TIMESTAMP not null on update CURRENT_TIMESTAMP,
                PRIMARY KEY (OXID),
                UNIQUE KEY FOUN10SCOPE (OXSHOPID, FOUN10ATTRID, FOUN10LANGID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Synonym rules, per shop AND per language: word equivalences do not
        // survive translation, so a second language starts empty rather than
        // inheriting German ones. Terms are stored as the merchant typed them
        // and normalised on read - storing the folded form as well would go
        // stale the moment the Normalizer's rules change.
        $this->addSql("
            CREATE TABLE IF NOT EXISTS foun10easysearchsynonym (
                OXID char(32) COLLATE latin1_general_ci not null,
                OXSHOPID int default 1 not null,
                FOUN10LANGID tinyint default 0 not null,
                FOUN10TYPE varchar(16) default 'both' not null,
                FOUN10TERM varchar(255) default '' not null,
                FOUN10SYNONYMS text null,
                FOUN10ACTIVE tinyint(1) default 1 not null,
                FOUN10SORT int default 0 not null,
                OXTIMESTAMP timestamp default CURRENT_TIMESTAMP not null on update CURRENT_TIMESTAMP,
                PRIMARY KEY (OXID),
                KEY FOUN10SCOPE (OXSHOPID, FOUN10LANGID, FOUN10ACTIVE, FOUN10SORT)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // What customers searched for and whether they found anything.
        //
        // Counted per term and day rather than logged per search: the questions
        // worth asking are "what do people search for" and "what finds nothing",
        // and both are counters. That keeps the table to the size of the
        // vocabulary instead of the traffic, and there is nothing in it that
        // identifies anybody - no session, no address, only the words.
        $this->addSql("
            CREATE TABLE IF NOT EXISTS foun10easysearchlog (
                OXID char(32) COLLATE latin1_general_ci not null,
                OXSHOPID int default 1 not null,
                FOUN10LANGID tinyint default 0 not null,
                FOUN10DAY date not null,
                FOUN10TERM varchar(255) default '' not null,
                FOUN10TERMRAW varchar(255) default '' not null,
                FOUN10EASYSEARCHES int default 0 not null,
                FOUN10HITS int default 0 not null,
                FOUN10CORRECTED varchar(255) default '' not null,
                FOUN10LASTSEEN datetime default null,
                OXTIMESTAMP timestamp default CURRENT_TIMESTAMP not null on update CURRENT_TIMESTAMP,
                PRIMARY KEY (OXID),
                UNIQUE KEY FOUN10ENTRY (OXSHOPID, FOUN10LANGID, FOUN10DAY, FOUN10TERM),
                KEY FOUN10ZERO (OXSHOPID, FOUN10LANGID, FOUN10HITS, FOUN10DAY),
                KEY FOUN10TOP (OXSHOPID, FOUN10LANGID, FOUN10DAY, FOUN10EASYSEARCHES)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(Schema $schema): void
    {
        // Do nothing
    }
}
