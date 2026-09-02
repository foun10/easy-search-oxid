<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Command\BenchmarkCommand;

/**
 * BenchmarkCommand with the dictionary table and the filesystem supplied by
 * the test.
 *
 * The written file is kept in memory rather than on disk: what matters about
 * --json is what goes into it, and what matters about --compare is what comes
 * out - neither needs a real path.
 */
class TestableBenchmarkCommand extends BenchmarkCommand
{
    /** @var array<int, array<string, mixed>> Rows the dictionary query returns */
    public array $dictionaryRows = [];

    public ?string $dictionaryFailure = null;

    /** @var string[] */
    public array $queries = [];

    /** @var array<int, array<string, mixed>> */
    public array $queryParameters = [];

    /** @var array<string, string> Path => contents, for both directions */
    public array $files = [];

    /** @var string[] Paths that exist but cannot be read */
    public array $unreadable = [];

    protected function fetchRows(string $sql, array $parameters = []): array
    {
        $this->queries[] = $sql;
        $this->queryParameters[] = $parameters;

        if ($this->dictionaryFailure !== null) {
            throw new \RuntimeException($this->dictionaryFailure);
        }

        return $this->dictionaryRows;
    }

    protected function writeFile(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    protected function readFile(string $path): ?string
    {
        if (in_array($path, $this->unreadable, true)) {
            return null;
        }

        return $this->files[$path] ?? null;
    }

    /**
     * Exposed because it is the one piece of statistics in the command, and
     * driving it through a whole measured run would only obscure it.
     *
     * @param float[] $sorted
     */
    public function percentileOf(array $sorted, float $percentile): float
    {
        return $this->percentile($sorted, $percentile);
    }
}
