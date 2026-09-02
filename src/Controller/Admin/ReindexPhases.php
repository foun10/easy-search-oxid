<?php
declare(strict_types=1);

namespace foun10\EasySearch\Controller\Admin;

use foun10\EasySearch\Core\RequestValues;
use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Index\DictionaryBuilder;
use foun10\EasySearch\Index\DocumentProvider;
use foun10\EasySearch\Index\IndexWriterInterface;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Throwable;

/**
 * The batched reindex, driven from the browser.
 *
 * A web request cannot rebuild a 150k catalogue - it would run past
 * max_execution_time long before finishing - so the browser walks it one tick
 * at a time and each tick does a slice. The cursor lives in the client, which
 * keeps this endpoint stateless: no run state to store, and nothing to clean up
 * when somebody closes the tab halfway through.
 *
 * Shared by both screens that can start a rebuild. Which phases actually run is
 * the caller's choice - the attribute screen asks only for what its own settings
 * affect, the index screen offers each phase on its own - so the phases here are
 * independent steps rather than a fixed chain.
 */
trait ReindexPhases
{
    use RequestValues;

    /**
     * One step of a reindex, answered as JSON.
     *
     * A web request cannot rebuild a 150k catalogue - it would run past
     * max_execution_time long before finishing - so the browser drives the run
     * one batch at a time and this endpoint does a slice of it.
     *
     * The cursor lives in the client between ticks, which keeps the endpoint
     * stateless: no run state to store, none to clean up when somebody closes
     * the tab halfway through.
     *
     * Only the shop being edited is rebuilt, using the in place path. The swap
     * path would replace the whole table and take the other shops' rows with
     * it, and its shadow tables cannot span requests anyway.
     */
    public function reindexTick(): void
    {
        $request = $this->getRequest();
        $shopId = $this->getEditShopId();
        $langId = $this->toInt($request->getRequestEscapedParameter('langId'));
        $phase = $this->toString($request->getRequestEscapedParameter('phase'));

        try {
            $payload = match ($phase) {
                Reindex::PHASE_CLEAR => $this->clearPhase($shopId, $langId),
                Reindex::PHASE_CATEGORY => $this->categoryPhase($shopId, $langId),
                Reindex::PHASE_DICTIONARY => $this->dictionaryPhase($shopId, $langId),
                default => $this->indexPhase($shopId, $langId, $request),
            };

            $this->sendJson(['ok' => true] + $payload);
        } catch (Throwable $exception) {
            $this->logError('foun10EasySearch: reindex tick failed - ' . $exception->getMessage(), $exception);

            $this->sendJson(['ok' => false, 'message' => $exception->getMessage()]);
        }
    }

    /**
     * Removes a slice of the scope's existing rows.
     *
     * Kept apart from indexing because clearing a scope in one statement
     * measured 21 seconds on a 150k catalogue - past what a web request may
     * take, and the very thing the batching exists to avoid.
     *
     * @return array<string, mixed>
     */
    protected function clearPhase(int $shopId, int $langId): array
    {
        $deleted = $this->getService(IndexWriterInterface::class)
            ->clearScopeBatch($shopId, $langId, Reindex::CLEAR_BATCH_SIZE);

        return [
            'phase' => Reindex::PHASE_CLEAR,
            'deleted' => $deleted,
            'finished' => $deleted === 0,
        ];
    }

    /**
     * Indexes one batch, continuing from the cursor the browser carries.
     *
     * @return array<string, mixed>
     */
    protected function indexPhase(int $shopId, int $langId, $request): array
    {
        $lastId = $this->toString($request->getRequestEscapedParameter('lastId'));
        $done = max(0, $this->toInt($request->getRequestEscapedParameter('done')));

        $provider = $this->getService(DocumentProvider::class);
        $writer = $this->getService(IndexWriterInterface::class);

        // The clear phase already emptied the scope, so every tick joins a run
        // rather than starting one - begin() here would delete it all again.
        $writer->resume([['shopId' => $shopId, 'langId' => $langId]]);

        $batchSize = $this->getRequestedBatchSize($request);

        $batch = $provider->provideBatch($shopId, $langId, $lastId, $batchSize);
        $writer->write($batch['documents']);

        $written = count($batch['documents']);
        $done += $written;
        $finished = $written < $batchSize;

        if ($finished) {
            $writer->commit();
        }

        return [
            'phase' => Reindex::PHASE_INDEX,
            'done' => $done,
            'total' => $lastId === ''
                ? $provider->countArticles($shopId, $langId)
                : $this->toInt($request->getRequestEscapedParameter('total')),
            'lastId' => $batch['lastId'],
            'finished' => $finished,
            // Echoed back so the browser tunes against what actually ran, not
            // against what it asked for.
            'batchSize' => $batchSize,
        ];
    }

    /**
     * How many documents this tick may build, as the browser asked - clamped,
     * because the number arrives from a form field.
     *
     * @param \OxidEsales\Eshop\Core\Request $request
     */
    protected function getRequestedBatchSize($request): int
    {
        $requested = $this->toInt($request->getRequestEscapedParameter('batchSize'));

        if ($requested <= 0) {
            return Reindex::BATCH_SIZE;
        }

        return max(Reindex::BATCH_MIN, min(Reindex::BATCH_MAX, $requested));
    }

    /**
     * Refreshes the scope's category assignments once its documents are in.
     *
     * Its own phase rather than a step of the dictionary, because it can
     * decline: rebuilding while the ERP import has oxobject2category truncated
     * would blank every category page, so the writer refuses and keeps what is
     * live. That refusal has to reach the screen instead of being swallowed by
     * a phase that reports something else.
     *
     * Derived in SQL and measured well under a second, so it needs no batching
     * of its own.
     *
     * @return array<string, mixed>
     */
    protected function categoryPhase(int $shopId, int $langId): array
    {
        $result = $this->getService(IndexWriterInterface::class)
            ->rebuildCategories($shopId, $langId);

        return [
            'phase' => Reindex::PHASE_CATEGORY,
            'categories' => $result->getWritten(),
            'published' => $result->isPublished(),
            'message' => $result->describe(),
            'finished' => true,
        ];
    }

    /**
     * Builds the suggest dictionary for a finished scope.
     *
     * Its own phase so it gets a whole request to itself rather than sharing
     * one with the last index batch. It reads the scope in one pass - the
     * term table it accumulates cannot be carried between requests - so it is
     * the one step here whose cost grows with the catalogue.
     *
     * @return array<string, mixed>
     */
    protected function dictionaryPhase(int $shopId, int $langId): array
    {
        $terms = $this->getService(DictionaryBuilder::class)->build($shopId, $langId);

        return [
            'phase' => Reindex::PHASE_DICTIONARY,
            'terms' => $terms,
            'finished' => true,
        ];
    }

    /**
     * Languages of the shop being edited, so the browser can walk them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReindexScopes(): array
    {
        $shopId = $this->getEditShopId();
        $scopes = [];

        // Active only. Indexing a language the shop does not serve costs the
        // same minutes as one it does, for a catalogue nobody can search.
        foreach ($this->getService(ShopLanguages::class)->getActiveIds($shopId) as $langId) {
            $scopes[] = ['langId' => $langId];
        }

        return $scopes;
    }

    protected function sendJson(array $payload): void
    {
        $utils = Registry::getUtils();
        $utils->setHeader('Content-Type: application/json; charset=utf-8');
        $utils->showMessageAndExit((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The request, on its own so a tick can be driven without one.
     *
     * @return \OxidEsales\Eshop\Core\Request
     */
    protected function getRequest()
    {
        return Registry::getRequest();
    }

    /**
     * A failed tick, on its own for the same reason - and because what it has
     * to prove is that the browser is told, not what the log line reads like.
     */
    protected function logError(string $message, Throwable $exception): void
    {
        Registry::getLogger()->error($message, ['exception' => $exception]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function getService(string $id): object
    {
        return ContainerFactory::getInstance()->getContainer()->get($id);
    }

}
