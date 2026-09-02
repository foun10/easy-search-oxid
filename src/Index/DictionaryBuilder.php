<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index;

use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Correction\ColognePhonetic;
use foun10\EasySearch\Correction\Normalizer;
use foun10\EasySearch\Index\MySql\IndexTables;
use OxidEsales\Eshop\Core\DatabaseProvider;

/**
 * Derives the correction dictionary from the finished index.
 *
 * Runs after indexing, not from the catalogue directly: the index already
 * holds exactly the products a customer can reach, so the dictionary cannot
 * suggest a term that leads nowhere.
 *
 * Terms are weighted by where they appear. A word in a product title or brand
 * is far more likely to be what someone typed than a word from a long
 * description, and the weight decides which candidate wins when two are the
 * same edit distance away.
 */
class DictionaryBuilder
{
    public const TABLE = 'foun10easysearchdictionary';

    public const SOURCE_TITLE = 'title';
    public const SOURCE_BRAND = 'brand';
    public const SOURCE_CATEGORY = 'category';
    public const SOURCE_ATTRIBUTE = 'attribute';

    /**
     * Frequency multipliers per source.
     */
    protected const SOURCE_WEIGHT = [
        self::SOURCE_TITLE => 5,
        self::SOURCE_BRAND => 5,
        self::SOURCE_CATEGORY => 3,
        self::SOURCE_ATTRIBUTE => 2,
    ];

    protected const READ_BATCH_SIZE = 2000;
    protected const INSERT_BATCH_SIZE = 500;

    /**
     * Longer strings are not words but data artefacts, and the column is
     * sized accordingly.
     */
    protected const MAX_TERM_LENGTH = 64;

    public function __construct(
        protected Normalizer $normalizer,
        protected ColognePhonetic $colognePhonetic,
        protected IndexTables $tables
    ) {
    }

    /**
     * Rebuilds the dictionary for one scope and returns the number of terms.
     */
    public function build(int $shopId, int $langId): int
    {
        $terms = [];

        $this->collectFromIndex($shopId, $langId, $terms);
        $this->collectFromAttributes($shopId, $langId, $terms);

        $this->clear($shopId, $langId);
        $this->store($shopId, $langId, $terms);

        return count($terms);
    }

    /**
     * @param array<string, array{frequency: int, source: string, raw: string}> $terms
     */
    protected function collectFromIndex(int $shopId, int $langId, array &$terms): void
    {
        $offset = 0;

        while (true) {
            $sql = '
                SELECT FOUN10TITLE, FOUN10BRAND, FOUN10CATEGORYPATHS
                FROM ' . $this->tables->index($shopId) . '
                WHERE FOUN10LANGID = :langId AND FOUN10VISIBLE = 1
                LIMIT ' . $offset . ', ' . self::READ_BATCH_SIZE;

            $rows = DatabaseHelper::fetchAll($sql, [':langId' => $langId]);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $this->addTerms((string) $row['FOUN10TITLE'], self::SOURCE_TITLE, $terms);
                $this->addTerms((string) $row['FOUN10BRAND'], self::SOURCE_BRAND, $terms);
                $this->addTerms(
                    str_replace(['%%', '>'], ' ', (string) $row['FOUN10CATEGORYPATHS']),
                    self::SOURCE_CATEGORY,
                    $terms
                );
            }

            $offset += self::READ_BATCH_SIZE;
        }
    }

    /**
     * Attribute values are read distinctly, not per product: indexing "rot"
     * once per red variant would drown out everything else.
     *
     * Since the facet index only holds filterable attributes, these are colour,
     * size and material style words - exactly the vocabulary worth correcting
     * and suggesting. Values of non-filterable attributes stay findable through
     * the fulltext blob, they just do not become dictionary terms.
     *
     * @param array<string, array{frequency: int, source: string, raw: string}> $terms
     */
    protected function collectFromAttributes(int $shopId, int $langId, array &$terms): void
    {
        $sql = '
            SELECT DISTINCT FOUN10VALUE
            FROM ' . $this->tables->attribute($shopId) . '
            WHERE FOUN10LANGID = :langId';

        $values = (array) DatabaseProvider::getDb()->getCol($sql, [':langId' => $langId]);

        foreach ($values as $value) {
            $this->addTerms((string) $value, self::SOURCE_ATTRIBUTE, $terms);
        }
    }

    /**
     * @param array<string, array{frequency: int, source: string, raw: string}> $terms
     */
    protected function addTerms(string $text, string $source, array &$terms): void
    {
        if (trim($text) === '') {
            return;
        }

        $weight = self::SOURCE_WEIGHT[$source] ?? 1;

        foreach ($this->normalizer->tokenize($text) as $token) {
            if (mb_strlen($token) > self::MAX_TERM_LENGTH) {
                continue;
            }

            // Pure numbers are article numbers and sizes, useless as spelling
            // corrections and noisy in suggest.
            if (ctype_digit($token)) {
                continue;
            }

            if (!isset($terms[$token])) {
                $terms[$token] = ['frequency' => 0, 'source' => $source, 'raw' => $token];
            }

            $terms[$token]['frequency'] += $weight;

            // The strongest source a term appears in wins the label.
            if ($weight > (self::SOURCE_WEIGHT[$terms[$token]['source']] ?? 0)) {
                $terms[$token]['source'] = $source;
            }
        }
    }

    /**
     * @param array<string, array{frequency: int, source: string, raw: string}> $terms
     */
    protected function store(int $shopId, int $langId, array $terms): void
    {
        if ($terms === []) {
            return;
        }

        $database = DatabaseProvider::getDb();
        $batch = [];

        foreach ($terms as $term => $data) {
            $batch[] = '(' . implode(', ', [
                $database->quote(md5($term . '_' . $shopId . '_' . $langId)),
                $shopId,
                $langId,
                $database->quote($term),
                $database->quote($data['raw']),
                $database->quote($this->normalizer->getBucket($term)),
                $database->quote($this->colognePhonetic->encode($term)),
                $database->quote(''),
                mb_strlen($term),
                $data['frequency'],
                $database->quote($data['source']),
            ]) . ')';

            if (count($batch) >= self::INSERT_BATCH_SIZE) {
                $this->flush($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->flush($batch);
        }
    }

    /**
     * @param string[] $batch
     */
    protected function flush(array $batch): void
    {
        $sql = 'INSERT INTO ' . self::TABLE . ' (
                OXID, OXSHOPID, FOUN10LANGID, FOUN10TERM, FOUN10TERMRAW, FOUN10BUCKET,
                FOUN10PHONETIC, FOUN10PARTS, FOUN10LENGTH, FOUN10FREQUENCY, FOUN10SOURCE
            ) VALUES ' . implode(', ', $batch) . '
            ON DUPLICATE KEY UPDATE FOUN10FREQUENCY = VALUES(FOUN10FREQUENCY)';

        DatabaseProvider::getDb()->execute($sql);
    }

    protected function clear(int $shopId, int $langId): void
    {
        DatabaseProvider::getDb()->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId',
            [':shopId' => $shopId, ':langId' => $langId]
        );
    }
}
