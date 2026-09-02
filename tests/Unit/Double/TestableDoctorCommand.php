<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Command\DoctorCommand;
use foun10\EasySearch\Engine\SearchEngineInterface;
use RuntimeException;

/**
 * DoctorCommand with the server, the index and the clock supplied by the test.
 *
 * The command asks the installation four kinds of question - what the server is
 * configured to, what the tables weigh, what the dictionary holds, and how long
 * a search takes - and every one of them is answered here from a named array,
 * chosen by what the statement reads from. That is deliberate: the command's
 * whole output is derived from those four answers, so a test that can set them
 * can drive every finding it knows how to make.
 *
 * The clock is a seam of its own because the two thresholds it feeds - half a
 * second for a hint, two seconds for a problem - would otherwise cost a test
 * two real seconds to reach.
 *
 * Note where the seams sit: below the catches, not above them. fetchAll() and
 * scalar() keep their own try/catch, so `$databaseFails` drives the real
 * swallowing code rather than replacing it.
 */
class TestableDoctorCommand extends DoctorCommand
{
    /** @var int[] The installation's shops, when no --shop-id names one */
    public array $allShopIds = [1];

    /** @var array<string, string> Server variables, as SHOW VARIABLES would report them */
    public array $serverVariables = [];

    /** @var array<string, array{rows: int, bytes: int}> The tables the database has, keyed by name */
    public array $tableStats = [];

    /** @var array<int, array{term: string, frequency: int|string}> Dictionary rows below the token size */
    public array $shortTerms = [];

    /** @var string|null The most frequent indexed word, or null for a dictionary that has none */
    public ?string $busiestTerm = null;

    /** @var array<string, int> COUNT(*) answers, keyed by table name */
    public array $scopeCounts = [];

    /** @var array<string, string> MAX(OXTIMESTAMP) answers, keyed by table name */
    public array $timestamps = [];

    /** @var string[] Every statement that went through the row seam, in order */
    public array $queries = [];

    /** @var array<int, array<string, mixed>> The bound parameters of each of them, in the same order */
    public array $queryParameters = [];

    /** @var string[] Every statement that went through the scalar seam, in order */
    public array $scalarQueries = [];

    /** A database that answers nothing - the broken installation this command exists to describe */
    public bool $databaseFails = false;

    public ?SearchEngineInterface $engine = null;

    public int $engineLookups = 0;

    /** @var float[] Readings handed to now(), consumed in order; the real clock once they run out */
    public array $clock = [];

    /**
     * @return int[]
     */
    protected function getAllShopIds(): array
    {
        return $this->allShopIds;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<int, array<string, mixed>>
     */
    protected function query(string $sql, array $parameters = []): array
    {
        $this->queries[] = $sql;
        $this->queryParameters[] = $parameters;

        if ($this->databaseFails) {
            throw new RuntimeException('the database is not answering');
        }

        if (str_contains($sql, 'SHOW VARIABLES LIKE')) {
            return $this->answerServerVariable($sql);
        }

        if (str_contains($sql, 'information_schema.tables')) {
            $stats = $this->tableStats[(string) ($parameters[':table'] ?? '')] ?? null;

            return $stats === null
                ? []
                : [['estimate' => $stats['rows'], 'bytes' => $stats['bytes']]];
        }

        // Both dictionary reads name the same table; only one of them sums.
        if (str_contains($sql, 'SUM(FOUN10FREQUENCY)')) {
            return array_map(
                static fn (array $row): array => ['term' => $row['term'], 'frequency' => $row['frequency']],
                $this->shortTerms
            );
        }

        return $this->busiestTerm === null ? [] : [['term' => $this->busiestTerm]];
    }

    protected function queryScalar(string $sql): mixed
    {
        $this->scalarQueries[] = $sql;

        if ($this->databaseFails) {
            throw new RuntimeException('the database is not answering');
        }

        $table = $this->tableOf($sql);

        if (str_contains($sql, 'COUNT(*)')) {
            return $this->scopeCounts[$table] ?? 0;
        }

        return $this->timestamps[$table] ?? '';
    }

    protected function getEngine(): SearchEngineInterface
    {
        $this->engineLookups++;

        if ($this->engine === null) {
            // What a container without a search engine does, which is the only
            // way the command can fail to get one.
            throw new RuntimeException('no search engine is registered');
        }

        return $this->engine;
    }

    protected function now(): float
    {
        return array_shift($this->clock) ?? microtime(true);
    }

    /**
     * The statements that went through the row seam against one table.
     *
     * @return string[]
     */
    public function queriesAgainst(string $table): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => str_contains($sql, $table)
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function answerServerVariable(string $sql): array
    {
        foreach ($this->serverVariables as $name => $value) {
            if (str_contains($sql, "'" . $name . "'")) {
                return [['Value' => $value]];
            }
        }

        return [];
    }

    /**
     * The table a statement reads from, which is what picks its answer.
     */
    protected function tableOf(string $sql): string
    {
        return preg_match('/FROM\s+(\S+)/', $sql, $matches) === 1 ? $matches[1] : '';
    }
}
