<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index;

/**
 * Sink for index documents.
 *
 * Modelled as an explicit transaction so a writer can choose a safe strategy
 * without the caller knowing about it. The MySql writer fills a shadow table
 * and swaps it in on commit; a Meilisearch writer would push into a temporary
 * index and swap index aliases. Either way the live index is never half
 * written.
 *
 * The reindex command only ever sees begin/write/commit/rollback.
 */
interface IndexWriterInterface
{
    /**
     * Prepares a write run. Everything written until commit() must stay
     * invisible to search traffic.
     *
     * An empty $scopes means a full rebuild of every shop and language, which
     * lets a writer pick the cheapest safe strategy (shadow table, temporary
     * index). When scopes are given, only those may be replaced - everything
     * else has to survive untouched.
     *
     * @param array<int, array{shopId: int, langId: int}> $scopes
     */
    public function begin(array $scopes = []): void;

    /**
     * Rejoins a run that begin() opened in an earlier request.
     *
     * A web request cannot hold a full rebuild open, so the admin reindex spans
     * several of them. Implementations reattach to whatever begin() set up
     * rather than starting over.
     *
     * @param array<int, array{shopId: int, langId: int}> $scopes
     */
    public function resume(array $scopes = []): void;

    /**
     * Removes part of one scope's rows and reports how many went. Zero means
     * the scope is clear.
     *
     * begin() clears a scope in one statement, which suits the console but not
     * a web request: on a large catalogue that single delete runs far past the
     * time a request may take. Callers that must stay inside a request loop on
     * this instead.
     */
    public function clearScopeBatch(int $shopId, int $langId, int $limit): int;

    /**
     * @param IndexDocument[] $documents
     */
    public function write(array $documents): void;

    /**
     * Makes everything written since begin() live.
     */
    public function commit(): void;

    /**
     * Discards everything written since begin() and leaves the live index
     * untouched. Must be safe to call after a partial failure.
     */
    public function rollback(): void;

    /**
     * Removes a single article from the live index, for incremental updates
     * out of the ERP import rather than a full rebuild.
     */
    public function delete(string $articleId, int $shopId, int $langId): void;

    /**
     * Rebuilds the category assignments of one scope from the shop's current
     * data, without touching anything else in the index.
     *
     * Deliberately not part of a document: category assignments change with
     * every ERP import while titles and descriptions rarely do, and rebuilding
     * them is cheap enough to run several times a day. Tying them to the slow
     * document pass would mean either stale categories or needless full
     * rebuilds. A Meilisearch writer implements this as partial document
     * updates; the MySql one derives a link table in SQL.
     *
     * Must be atomic from a reader's point of view, and must refuse to publish
     * a result that looks like it was read while the ERP import had the source
     * emptied - see RebuildResult. $force overrides that refusal for the
     * legitimate case of a catalogue that really did shrink.
     */
    public function rebuildCategories(int $shopId, int $langId, bool $force = false): RebuildResult;
}
