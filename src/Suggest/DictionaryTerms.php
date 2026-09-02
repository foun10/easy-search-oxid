<?php
declare(strict_types=1);

namespace foun10\EasySearch\Suggest;

use foun10\EasySearch\Correction\SpellCorrector;
use foun10\EasySearch\Index\DictionaryBuilder;
use OxidEsales\Eshop\Core\DatabaseProvider;

/**
 * Completed search terms for the suggest box.
 *
 * Prefix match against the correction dictionary, ordered by how often a term
 * occurs in the catalogue. If the prefix matches nothing the term is run
 * through the corrector first, so a customer who mistyped early in the word
 * still gets suggestions instead of an empty dropdown.
 *
 * Backend neutral, and used by both connectors: the dictionary is derived from
 * whatever is indexed, and which store that index lives in does not change what
 * a word completes to. A Meilisearch-only installation still needs the
 * dictionary built - see DictionaryBuilder, which reads the MySQL index.
 */
class DictionaryTerms
{
    public function __construct(
        protected SpellCorrector $spellCorrector
    ) {
    }

    /**
     * @return string[]
     */
    public function complete(string $term, int $shopId, int $langId, int $limit): array
    {
        if (trim($term) === '' || $limit < 1) {
            return [];
        }

        $terms = $this->fetchByPrefix($term, $shopId, $langId, $limit);

        if ($terms !== []) {
            return $terms;
        }

        $corrected = $this->spellCorrector->correct($term, $shopId, $langId);

        if ($corrected === null || !$corrected->hasChanged()) {
            return [];
        }

        return $this->fetchByPrefix($corrected->getCorrected(), $shopId, $langId, $limit);
    }

    /**
     * @return string[]
     */
    protected function fetchByPrefix(string $prefix, int $shopId, int $langId, int $limit): array
    {
        $database = DatabaseProvider::getDb();

        $sql = '
            SELECT FOUN10TERMRAW
            FROM ' . DictionaryBuilder::TABLE . '
            WHERE OXSHOPID = :shopId
                AND FOUN10LANGID = :langId
                AND FOUN10TERM LIKE ' . $database->quote($prefix . '%') . '
            ORDER BY FOUN10FREQUENCY DESC
            LIMIT ' . $limit;

        try {
            return (array) $database->getCol($sql, [
                ':shopId' => $shopId,
                ':langId' => $langId,
            ]);
        } catch (\Throwable $exception) {
            // Dictionary table not migrated or not built yet - a suggest box
            // without completions is better than a broken one.
            return [];
        }
    }
}
