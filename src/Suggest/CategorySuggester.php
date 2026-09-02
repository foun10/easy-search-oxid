<?php
declare(strict_types=1);

namespace foun10\EasySearch\Suggest;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\TableViewNameGenerator;

/**
 * Categories whose name contains the typed text.
 *
 * Matched against oxcategories rather than the search index on purpose: the
 * question is whether the customer is naming a category, not whether some
 * product in it matches. A shop has a few hundred categories, so a LIKE over
 * the view is cheap - and it costs the search backend nothing, whichever one is
 * in use.
 *
 * Matched on the raw input, because category titles are stored as written
 * ("Waesche" never matches "Wäsche" the other way round). Prefix hits sort
 * first, then the shortest title - "BHs" should beat "BHs und Bustiers".
 */
class CategorySuggester
{
    /**
     * @return string[] Category IDs
     */
    public function suggest(string $term, int $shopId, int $langId, int $limit): array
    {
        $term = trim($term);

        if ($term === '' || $limit < 1) {
            return [];
        }

        $database = DatabaseProvider::getDb();
        $categoryView = Registry::get(TableViewNameGenerator::class)
            ->getViewName('oxcategories', $langId, $shopId);

        $quotedContains = $database->quote('%' . $term . '%');
        $quotedPrefix = $database->quote($term . '%');

        $sql = "
            SELECT OXID
            FROM {$categoryView}
            WHERE OXACTIVE = 1
                AND OXHIDDEN = 0
                AND OXTITLE LIKE {$quotedContains}
            ORDER BY (OXTITLE LIKE {$quotedPrefix}) DESC, CHAR_LENGTH(OXTITLE) ASC
            LIMIT {$limit}
        ";

        return (array) $database->getCol($sql);
    }
}
