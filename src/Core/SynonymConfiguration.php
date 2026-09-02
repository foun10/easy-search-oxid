<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use foun10\EasySearch\Synonym\SynonymRule;
use OxidEsales\Eshop\Core\DatabaseProvider;

/**
 * Reads and writes the synonym rules of one shop and language.
 *
 * Same reasoning as AttributeConfiguration: this is editorial content a
 * merchant maintains in the admin, so it lives in its own table where
 * oe:module:deploy-configurations cannot overwrite it on the next release.
 *
 * Scoped per language as well as per shop. A synonym list is language bound -
 * "bh ↔ büstenhalter" says nothing about an English catalogue - so a second
 * language starts empty rather than inheriting German equivalences.
 *
 * Reads are memoised per scope for the request: the expander asks on every
 * search and on every suggest keystroke, and the rules do not change mid
 * request.
 */
class SynonymConfiguration
{
    public const TABLE = 'foun10easysearchsynonym';

    /**
     * @var array<string, SynonymRule[]>
     */
    protected array $cache = [];

    /**
     * All rules of one scope, in the order the merchant arranged them.
     *
     * @return SynonymRule[]
     */
    public function getRules(int $shopId, int $langId): array
    {
        $key = $shopId . '_' . $langId;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $sql = '
            SELECT FOUN10TYPE, FOUN10TERM, FOUN10SYNONYMS, FOUN10ACTIVE
            FROM ' . self::TABLE . '
            WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId
            ORDER BY FOUN10SORT ASC';

        try {
            $rows = DatabaseHelper::fetchAll($sql, [':shopId' => $shopId, ':langId' => $langId]);
        } catch (\Throwable $exception) {
            // Table not migrated yet: behave like nothing is configured rather
            // than taking the search down.
            $rows = [];
        }

        $rules = [];

        foreach ($rows as $row) {
            $rules[] = new SynonymRule(
                (string) $row['FOUN10TYPE'],
                (string) $row['FOUN10TERM'],
                (string) ($row['FOUN10SYNONYMS'] ?? ''),
                (int) $row['FOUN10ACTIVE'] === 1
            );
        }

        return $this->cache[$key] = $rules;
    }

    /**
     * Only the rules that are switched on, for the query path.
     *
     * @return SynonymRule[]
     */
    public function getActiveRules(int $shopId, int $langId): array
    {
        return array_values(array_filter(
            $this->getRules($shopId, $langId),
            static fn (SynonymRule $rule): bool => $rule->isActive() && $rule->isComplete()
        ));
    }

    /**
     * Replaces the whole rule set of one scope.
     *
     * Delete plus insert, like AttributeConfiguration::save(): the screen
     * always submits the complete list, and a diff would have to guess which
     * rows were meant to be removed.
     *
     * Incomplete rows are dropped rather than stored - a rule with only one
     * side does nothing, and keeping it would just be a row that silently never
     * fires.
     *
     * @param array<int, array{type?: string, term?: string, synonyms?: string, active?: bool}> $entries
     *
     * @return int Number of rules actually stored
     */
    public function save(int $shopId, int $langId, array $entries): int
    {
        $database = DatabaseProvider::getDb();

        $database->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId',
            [':shopId' => $shopId, ':langId' => $langId]
        );

        unset($this->cache[$shopId . '_' . $langId]);

        $values = [];
        $sort = 0;

        foreach ($entries as $entry) {
            $rule = new SynonymRule(
                (string) ($entry['type'] ?? SynonymRule::TYPE_BOTH),
                trim((string) ($entry['term'] ?? '')),
                $this->cleanSynonyms((string) ($entry['synonyms'] ?? '')),
                !empty($entry['active'])
            );

            if (!$rule->isComplete()) {
                continue;
            }

            $sort += 10;

            $values[] = '(' . implode(', ', [
                $database->quote(md5($shopId . '_' . $langId . '_' . $sort . '_' . $rule->getTerm())),
                $shopId,
                $langId,
                $database->quote($rule->getType()),
                $database->quote($rule->getTerm()),
                $database->quote($rule->getSynonyms()),
                $rule->isActive() ? 1 : 0,
                $sort,
            ]) . ')';
        }

        if ($values === []) {
            return 0;
        }

        $database->execute(
            'INSERT INTO ' . self::TABLE . '
                (OXID, OXSHOPID, FOUN10LANGID, FOUN10TYPE, FOUN10TERM, FOUN10SYNONYMS, FOUN10ACTIVE, FOUN10SORT)
             VALUES ' . implode(', ', $values)
        );

        return count($values);
    }

    /**
     * Tidies the synonym side back into a canonical comma separated list, so
     * the screen shows "a, b, c" no matter how it was pasted in.
     */
    protected function cleanSynonyms(string $value): string
    {
        $entries = array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $entry): bool => $entry !== ''
        );

        return implode(', ', array_unique($entries));
    }
}
