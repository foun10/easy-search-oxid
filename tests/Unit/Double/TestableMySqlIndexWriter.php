<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Index\MySql\MySqlIndexWriter;
use RuntimeException;

/**
 * MySqlIndexWriter with the database replaced by a transcript.
 *
 * Everything the writer sends out is recorded in order, which is the point:
 * the class is an argument about sequence - fill a shadow table, derive from
 * what was written, index it, swap it in one statement, drop what it replaced -
 * and the order of the statements is the behaviour worth pinning.
 *
 * Counts are answered from a queue in the order the class asks for them
 * (available, previous, written), and `failWhenSqlContains` lets a test make
 * one statement fail to prove the rollback.
 */
class TestableMySqlIndexWriter extends MySqlIndexWriter
{
    /** @var array<int, array{sql: string, parameters: array<string, mixed>}> */
    public array $statements = [];

    /** @var array<int, array{sql: string, parameters: array<string, mixed>}> */
    public array $reads = [];

    /** @var int[] Answers for fetchCount(), in the order they are asked for */
    public array $counts = [];

    /** @var string[] Answer for fetchColumn() */
    public array $columnValues = [];

    /** @var string[] 'start', 'commit' and 'rollback', in order */
    public array $transaction = [];

    /** @var int[] */
    public array $allShopIds = [1];

    /**
     * Makes the first statement containing this fragment fail.
     */
    public string $failWhenSqlContains = '';

    /**
     * @return string[]
     */
    public function executedSql(): array
    {
        return array_column($this->statements, 'sql');
    }

    /**
     * The first statement containing a fragment, for asserting on its shape.
     */
    public function statementContaining(string $needle): string
    {
        foreach ($this->executedSql() as $sql) {
            if (str_contains($sql, $needle)) {
                return $sql;
            }
        }

        return '';
    }

    public function countStatementsContaining(string $needle): int
    {
        return count(array_filter(
            $this->executedSql(),
            static fn (string $sql): bool => str_contains($sql, $needle)
        ));
    }

    /**
     * Which statements ran, reduced to a keyword each, so a test can assert on
     * the order without repeating half the SQL.
     *
     * @return string[]
     */
    public function steps(): array
    {
        $steps = [];

        foreach ($this->executedSql() as $sql) {
            $sql = ltrim($sql);

            if (str_starts_with($sql, 'DROP TEMPORARY')) {
                $steps[] = 'drop-label-choice';
            } elseif (str_starts_with($sql, 'CREATE TEMPORARY')) {
                $steps[] = 'create-label-choice';
            } elseif (str_contains($sql, 'INSERT INTO ' . self::TABLE_LABEL_CHOICE)) {
                $steps[] = 'fill-label-choice';
            } elseif (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, 'indexattributegroup')) {
                $steps[] = 'fill-attribute-groups';
            } elseif (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, 'indexcategory')) {
                $steps[] = 'insert-categories';
            } elseif (str_starts_with($sql, 'ALTER TABLE')) {
                $steps[] = 'add-fulltext';
            } elseif (str_starts_with($sql, 'RENAME TABLE')) {
                $steps[] = 'swap';
            } elseif (str_starts_with($sql, 'INSERT IGNORE')) {
                $steps[] = 'insert-attributes';
            } elseif (str_starts_with($sql, 'INSERT INTO')) {
                $steps[] = 'insert-documents';
            } elseif (str_starts_with($sql, 'DELETE')) {
                $steps[] = 'delete';
            } else {
                $steps[] = 'other';
            }
        }

        return $steps;
    }

    protected function execute(string $sql, array $parameters = []): void
    {
        $this->statements[] = ['sql' => $sql, 'parameters' => $parameters];

        if ($this->failWhenSqlContains !== '' && str_contains($sql, $this->failWhenSqlContains)) {
            throw new RuntimeException('the database refused the statement');
        }
    }

    protected function fetchCount(string $sql): int
    {
        $this->reads[] = ['sql' => $sql, 'parameters' => []];

        return (int) (array_shift($this->counts) ?? 0);
    }

    protected function fetchColumn(string $sql, array $parameters = []): array
    {
        $this->reads[] = ['sql' => $sql, 'parameters' => $parameters];

        return $this->columnValues;
    }

    protected function quote(string $value): string
    {
        return "'" . $value . "'";
    }

    protected function quoteList(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => $this->quote($value), $values));
    }

    protected function startTransaction(): void
    {
        $this->transaction[] = 'start';
    }

    protected function commitTransaction(): void
    {
        $this->transaction[] = 'commit';
    }

    protected function rollbackTransaction(): void
    {
        $this->transaction[] = 'rollback';
    }

    protected function getViewName(string $table, int $langId, int $shopId): string
    {
        return 'oxv_' . $table . '_' . $shopId . '_' . $langId;
    }

    protected function getAllShopIds(): array
    {
        return $this->allShopIds;
    }
}
