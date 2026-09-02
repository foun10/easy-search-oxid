<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use OxidEsales\Eshop\Core\DatabaseProvider;

/**
 * Guards against OXID's shared fetch mode.
 *
 * DatabaseProvider::getDb() returns one shared connection and calls
 * setFetchMode() on it every single time - defaulting to FETCH_MODE_NUM. So
 * this sequence silently breaks:
 *
 *     $db = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC);
 *     $conditions = $builder->build($query);   // calls getDb() -> back to NUM
 *     $rows = $db->getAll($sql);               // numeric keys after all
 *
 * Nothing throws. The rows arrive numerically indexed, every $row['COLUMN']
 * reads as null, and the result looks like empty data rather than a bug - which
 * is exactly how it showed up: facet values with blank labels and a count of
 * zero.
 *
 * Acquiring the connection inside the call, immediately before executing,
 * removes the window in which anything else can change the mode.
 */
class DatabaseHelper
{
    /**
     * Rows keyed by column name.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fetchAll(string $sql, array $parameters = []): array
    {
        return (array) DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)
            ->getAll($sql, $parameters);
    }
}
