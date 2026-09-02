<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index\MySql;

use OxidEsales\Eshop\Core\DatabaseProvider;
use Throwable;

/**
 * Which table holds a shop's index.
 *
 * One table per subshop, `foun10easysearchindex_s2` and friends, rather than one
 * table with an OXSHOPID column. The reason is the fulltext index: InnoDB has
 * no composite fulltext key, so a search on a shared table uses the fulltext
 * index as its only access path and applies the shop condition to whatever that
 * returns. Measured on this catalogue, `MATCH spitze` handed back 73,224 rows
 * where shop 1 needed 15,751 - shop 1 and shop 2 share most of the catalogue,
 * so most product text sits in that index two to four times over. Splitting the
 * tables measured 3.4x on fulltext queries and 1.9x on everything else.
 *
 * It also simplifies rebuilding. A scoped rebuild used to replace rows in place
 * because a RENAME would have taken the other shops with it; with a table per
 * shop every rebuild can fill a shadow table and swap it, and clearing a scope
 * is a DROP rather than a delete measured at 21 seconds.
 *
 * Language stays a column. These shops serve one active language each, so a
 * table per language would double the table count and halve nothing.
 */
class IndexTables
{
    protected const SUFFIX_SHADOW = '_tmp';
    protected const SUFFIX_RETIRED = '_old';

    /**
     * @var array<string, bool>
     */
    protected array $known = [];

    public function __construct(
        protected TableSchema $schema
    ) {
    }

    public function index(int $shopId): string
    {
        return $this->name(TableSchema::INDEX, $shopId);
    }

    public function attribute(int $shopId): string
    {
        return $this->name(TableSchema::ATTRIBUTE, $shopId);
    }

    public function attributeGroup(int $shopId): string
    {
        return $this->name(TableSchema::ATTRIBUTE_GROUP, $shopId);
    }

    public function category(int $shopId): string
    {
        return $this->name(TableSchema::CATEGORY, $shopId);
    }

    public function name(string $table, int $shopId): string
    {
        return $table . '_s' . $shopId;
    }

    public function shadow(string $table): string
    {
        return $table . self::SUFFIX_SHADOW;
    }

    public function retired(string $table): string
    {
        return $table . self::SUFFIX_RETIRED;
    }

    /**
     * Creates the table if it is not there yet.
     *
     * Idempotent and cheap to call: the result is remembered, so a rebuild
     * writing hundreds of batches asks the database once.
     */
    public function ensure(string $table, int $shopId, bool $withFulltext = true): string
    {
        $name = $this->name($table, $shopId);

        if (isset($this->known[$name])) {
            return $name;
        }

        DatabaseProvider::getDb()->execute(
            $this->schema->getCreateStatement($table, $name, $withFulltext)
        );

        $this->known[$name] = true;

        return $name;
    }

    /**
     * Creates a shadow table to fill, replacing any left behind by a run that
     * died before its swap.
     */
    public function createShadow(string $table, int $shopId, bool $withFulltext = true): string
    {
        $name = $this->shadow($this->name($table, $shopId));

        $this->drop($name);
        DatabaseProvider::getDb()->execute(
            $this->schema->getCreateStatement($table, $name, $withFulltext)
        );

        return $name;
    }

    public function exists(string $name): bool
    {
        if (isset($this->known[$name])) {
            return $this->known[$name];
        }

        try {
            DatabaseProvider::getDb()->getOne('SELECT 1 FROM ' . $name . ' LIMIT 1');
        } catch (Throwable $exception) {
            // Missing table. Not an error: a shop that has never been indexed
            // has none, and the engine reports itself unavailable for it.
            return $this->known[$name] = false;
        }

        return $this->known[$name] = true;
    }

    public function drop(string $name): void
    {
        DatabaseProvider::getDb()->execute('DROP TABLE IF EXISTS ' . $name);
        unset($this->known[$name]);
    }

    /**
     * Forgets what it has seen. The writer calls this after a swap, where a
     * name that existed a moment ago now points at different data.
     */
    public function forget(): void
    {
        $this->known = [];
    }
}
