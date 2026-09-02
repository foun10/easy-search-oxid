<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index;

/**
 * The writer everything that is not the console talks to.
 *
 * IndexWriterInterface is aliased here, so the admin reindex fills whichever
 * backend the shop is configured for without knowing which one that is. The
 * console command bypasses this and asks IndexWriterLocator by name, because a
 * rebuild is exactly the moment where "fill the other one too" is a legitimate
 * thing to want.
 */
class ConfiguredIndexWriter implements IndexWriterInterface
{
    protected ?IndexWriterInterface $writer = null;

    public function __construct(
        protected IndexWriterLocator $indexWriterLocator
    ) {
    }

    public function begin(array $scopes = []): void
    {
        $this->resolve()->begin($scopes);
    }

    public function resume(array $scopes = []): void
    {
        $this->resolve()->resume($scopes);
    }

    public function clearScopeBatch(int $shopId, int $langId, int $limit): int
    {
        return $this->resolve()->clearScopeBatch($shopId, $langId, $limit);
    }

    public function write(array $documents): void
    {
        $this->resolve()->write($documents);
    }

    public function commit(): void
    {
        $this->resolve()->commit();
    }

    public function rollback(): void
    {
        $this->resolve()->rollback();
    }

    public function delete(string $articleId, int $shopId, int $langId): void
    {
        $this->resolve()->delete($articleId, $shopId, $langId);
    }

    public function rebuildCategories(int $shopId, int $langId, bool $force = false): RebuildResult
    {
        return $this->resolve()->rebuildCategories($shopId, $langId, $force);
    }

    protected function resolve(): IndexWriterInterface
    {
        return $this->writer ??= $this->indexWriterLocator->getConfigured();
    }
}
