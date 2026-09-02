<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Index\IndexDocument;
use foun10\EasySearch\Index\IndexWriterInterface;
use foun10\EasySearch\Index\RebuildResult;
use RuntimeException;

/**
 * An index writer that only remembers what it was told to do.
 *
 * The commands are about sequence too - begin, write, commit, and on any
 * failure a rollback that leaves the live index alone - so the steps are
 * recorded in one list and asserted as a whole.
 */
class SpyIndexWriter implements IndexWriterInterface
{
    /** @var string[] 'begin', 'write', 'commit', 'rollback', 'categories' in order */
    public array $steps = [];

    /** @var array<int, array{shopId: int, langId: int}> The scopes begin() was given */
    public array $beganWith = [];

    /** @var array<int, array{shopId: int, langId: int}> The scopes resume() was given */
    public array $resumedWith = [];

    /** @var array<int, array{shopId: int, langId: int, limit: int}> One entry per clearScopeBatch() call */
    public array $clearBatches = [];

    /**
     * Rows each clear tick reports as deleted, consumed in order. A browser
     * driven clear ticks until one of them answers zero, so a test that wants
     * a second tick has to say so here.
     *
     * @var int[]
     */
    public array $clearCounts = [];

    /** @var array<int, IndexDocument[]> One entry per write() call */
    public array $batches = [];

    /** @var array<int, array{shopId: int, langId: int, force: bool}> */
    public array $categoryRebuilds = [];

    /**
     * Keyed "shopId_langId"; anything not named here publishes.
     *
     * @var array<string, RebuildResult>
     */
    public array $categoryResults = [];

    /**
     * Makes the write() call with this index (zero based) fail.
     */
    public ?int $failOnBatch = null;

    public function begin(array $scopes = []): void
    {
        $this->steps[] = 'begin';
        $this->beganWith = $scopes;
    }

    public function resume(array $scopes = []): void
    {
        $this->steps[] = 'resume';
        $this->resumedWith = $scopes;
    }

    public function clearScopeBatch(int $shopId, int $langId, int $limit): int
    {
        $this->steps[] = 'clear';
        $this->clearBatches[] = ['shopId' => $shopId, 'langId' => $langId, 'limit' => $limit];

        return (int) array_shift($this->clearCounts);
    }

    public function write(array $documents): void
    {
        if ($this->failOnBatch === count($this->batches)) {
            throw new RuntimeException('the index refused the batch');
        }

        $this->steps[] = 'write';
        $this->batches[] = $documents;
    }

    public function commit(): void
    {
        $this->steps[] = 'commit';
    }

    public function rollback(): void
    {
        $this->steps[] = 'rollback';
    }

    public function delete(string $articleId, int $shopId, int $langId): void
    {
        $this->steps[] = 'delete';
    }

    public function rebuildCategories(int $shopId, int $langId, bool $force = false): RebuildResult
    {
        $this->steps[] = 'categories';
        $this->categoryRebuilds[] = ['shopId' => $shopId, 'langId' => $langId, 'force' => $force];

        return $this->categoryResults[$shopId . '_' . $langId]
            ?? RebuildResult::published('category assignments', 10, 8);
    }

    /**
     * The sizes of the batches it was handed, which is what a --batch-size is
     * about.
     *
     * @return int[]
     */
    public function batchSizes(): array
    {
        return array_map('count', $this->batches);
    }

    public function documentsWritten(): int
    {
        return array_sum($this->batchSizes());
    }
}
