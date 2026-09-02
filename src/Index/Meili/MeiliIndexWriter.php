<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index\Meili;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Core\SynonymConfiguration;
use foun10\EasySearch\Index\IndexDocument;
use foun10\EasySearch\Index\IndexWriterInterface;
use foun10\EasySearch\Index\RebuildResult;
use foun10\EasySearch\Meili\IndexSchema;
use foun10\EasySearch\Meili\MeiliClient;
use foun10\EasySearch\Meili\MeiliConfiguration;
use foun10\EasySearch\Meili\MeiliException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\TableViewNameGenerator;
use RuntimeException;
use Throwable;

/**
 * Writes index documents into Meilisearch.
 *
 * The same two strategies as the MySql writer, expressed in what a document
 * store has instead of tables:
 *
 *  - Full rebuild: fill a shadow index per scope and swap the names in one
 *    /swap-indexes call. Search traffic keeps hitting the old index until that
 *    moment - the document-store counterpart of RENAME TABLE.
 *  - Scoped rebuild: clear that scope's index and refill it, because a swap
 *    would leave the scopes that were not rebuilt pointing at nothing.
 *
 * Writes are enqueued and not waited for. Meilisearch applies them in a single
 * ordered task queue, so waiting after every batch would serialise the whole
 * rebuild against the indexer; commit() waits once, for the last task, which
 * implies every earlier one.
 */
class MeiliIndexWriter implements IndexWriterInterface
{
    /**
     * Documents per partial-update request during a category rebuild. Larger
     * than the document batches because these carry two fields, not a long
     * description.
     */
    protected const CATEGORY_BATCH_SIZE = 2000;

    /**
     * Documents read per round trip while walking the index.
     */
    protected const DOCUMENT_READ_BATCH_SIZE = 5000;

    /**
     * Share of the live assignments the source has to still hold for a category
     * rebuild to publish - the ERP import guard, same rule and same reasoning
     * as MySqlIndexWriter::MIN_RETAINED_RATIO.
     */
    protected const MIN_RETAINED_RATIO = 0.5;

    protected bool $started = false;
    protected bool $swapMode = false;

    /**
     * @var array<int, array{shopId: int, langId: int}>
     */
    protected array $scopes = [];

    /**
     * Scopes this run has written to, keyed "shopId_langId" - what commit()
     * has to swap and rollback() has to throw away.
     *
     * @var array<string, array{shopId: int, langId: int}>
     */
    protected array $touchedScopes = [];

    /**
     * Index UIDs whose settings this run has already pushed.
     *
     * @var array<string, bool>
     */
    protected array $preparedIndexes = [];

    /**
     * The last task Meilisearch handed back. Its queue is ordered, so waiting
     * for this one means every write before it has been applied too.
     */
    protected int $lastTaskUid = 0;

    public function __construct(
        protected MeiliClient $client,
        protected MeiliConfiguration $configuration,
        protected IndexSchema $schema,
        protected AttributeConfiguration $attributeConfiguration,
        protected SynonymConfiguration $synonymConfiguration
    ) {
    }

    public function begin(array $scopes = []): void
    {
        if ($this->started) {
            throw new RuntimeException('Index write run already started');
        }

        $this->scopes = $scopes;
        $this->swapMode = $scopes === [];
        $this->started = true;
        $this->touchedScopes = [];
        $this->preparedIndexes = [];
        $this->lastTaskUid = 0;

        if ($this->swapMode) {
            // A run that died before its swap leaves shadow indexes behind, and
            // refilling one that already holds documents would mix two
            // catalogues.
            $this->dropShadowIndexes();

            return;
        }

        foreach ($scopes as $scope) {
            $this->clearScope($scope['shopId'], $scope['langId']);
        }
    }

    public function resume(array $scopes = []): void
    {
        // Nothing to reattach to: the shadow indexes an earlier request created
        // are sitting in Meilisearch under a name this object can derive again.
        $this->scopes = $scopes;
        $this->swapMode = $scopes === [];
        $this->started = true;
    }

    /**
     * One index holds exactly one scope, so clearing a scope is clearing its
     * index - a single task rather than a delete that has to be paged through.
     * The caller's loop ends on the second call, when the index reports empty.
     */
    public function clearScopeBatch(int $shopId, int $langId, int $limit): int
    {
        $uid = $this->configuration->getIndexUid($shopId, $langId);
        $documents = $this->countDocuments($uid);

        if ($documents === 0) {
            return 0;
        }

        $this->clearScope($shopId, $langId);
        $this->waitForPendingTasks();

        return $documents;
    }

    public function write(array $documents): void
    {
        if (!$this->started) {
            throw new RuntimeException('Index write run not started');
        }

        if ($documents === []) {
            return;
        }

        foreach ($this->groupByScope($documents) as $group) {
            $this->writeScope($group['shopId'], $group['langId'], $group['documents']);
        }
    }

    public function commit(): void
    {
        if (!$this->started) {
            throw new RuntimeException('Index write run not started');
        }

        $this->waitForPendingTasks();

        if ($this->swapMode) {
            $this->swapIndexes();
        }

        $this->reset();
    }

    public function rollback(): void
    {
        if ($this->swapMode) {
            foreach ($this->touchedScopes as $scope) {
                $this->deleteIndex($this->configuration->getShadowIndexUid($scope['shopId'], $scope['langId']));
            }
        }

        // A scoped run cannot be rolled back - that scope's index was cleared
        // before the first batch. Re-running the command repairs it.
        $this->reset();
    }

    public function delete(string $articleId, int $shopId, int $langId): void
    {
        $uid = $this->configuration->getIndexUid($shopId, $langId);

        try {
            // Same rule as DocumentProvider::buildDocumentId(): one article
            // appears once per shop and language, and the ID says which.
            $this->client->delete('/indexes/' . $uid . '/documents/' . md5($articleId . '_' . $shopId . '_' . $langId));
        } catch (MeiliException $exception) {
            if (!$exception->isNotFound()) {
                throw $exception;
            }
        }
    }

    /**
     * Refreshes one scope's category assignments as partial document updates.
     *
     * Which documents exist is asked of Meilisearch, not of the shop. That is
     * the difference to the MySql writer, which derives everything in one
     * INSERT ... SELECT, and it decides two things at once: only documents that
     * are actually indexed are touched - a partial update would otherwise
     * *create* a thin document for an article added since the last rebuild -
     * and the shop side stays a single read of oxobject2category instead of a
     * join against the article view, which on 116k articles is the difference
     * between one second and a minute and a half.
     *
     * Every indexed document is sent, including those that now sit in no
     * category at all: a partial update only touches the documents it names, so
     * leaving those out would keep a product listed in a category it was taken
     * out of.
     *
     * The ERP import guard works on the number of (document, category) pairs -
     * the source is counted before anything is written, and a source holding
     * less than half of what is live reads as a truncated oxobject2category
     * rather than a catalogue that shrank.
     */
    public function rebuildCategories(int $shopId, int $langId, bool $force = false): RebuildResult
    {
        $uid = $this->configuration->getIndexUid($shopId, $langId);

        if (!$this->indexExists($uid)) {
            // Nothing indexed yet - there is no assignment to protect and
            // nothing to write. A document rebuild carries the assignments of
            // its own run anyway.
            return RebuildResult::published('category assignments', 0, 0);
        }

        $assignments = $this->fetchGroupAssignments($shopId, $langId);
        $available = 0;

        foreach ($this->streamDocuments($uid) as $document) {
            $available += count($assignments[$document['groupId']] ?? []);
        }

        $previous = $this->countLiveAssignments($uid);

        if (!$force && $this->isImplausible($available, $previous)) {
            return RebuildResult::skipped('category assignments', $available, $previous);
        }

        $written = 0;
        $batch = [];

        foreach ($this->streamDocuments($uid) as $document) {
            $categoryIds = $assignments[$document['groupId']] ?? [];

            $batch[] = [
                IndexSchema::PRIMARY_KEY => $document['id'],
                'categoryIds' => $categoryIds,
            ];
            $written += count($categoryIds);

            if (count($batch) >= self::CATEGORY_BATCH_SIZE) {
                $this->pushPartialDocuments($uid, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->pushPartialDocuments($uid, $batch);
        }

        $this->waitForPendingTasks();

        return RebuildResult::published('category assignments', $written, $previous);
    }

    /**
     * Which categories each product group sits in, read in one go.
     *
     * A whole shop's assignments are a few hundred thousand pairs of two IDs -
     * small enough to hold, and holding them is what turns the rebuild into a
     * lookup per document instead of a join per batch.
     *
     * @return array<string, string[]> Category IDs keyed by group ID
     */
    protected function fetchGroupAssignments(int $shopId, int $langId): array
    {
        $view = $this->getViewName('oxobject2category', $langId, $shopId);
        $assignments = [];

        foreach ($this->fetchRows("SELECT OXOBJECTID, OXCATNID FROM {$view}") as $row) {
            $assignments[(string) $row['OXOBJECTID']][] = (string) $row['OXCATNID'];
        }

        return $assignments;
    }

    /**
     * The IDs of everything in one index, in pages.
     *
     * Only the two fields the category rebuild needs, so a pass over the whole
     * index moves a few megabytes rather than the descriptions.
     *
     * @return \Generator<int, array{id: string, groupId: string}>
     */
    protected function streamDocuments(string $uid): \Generator
    {
        $offset = 0;

        while (true) {
            $response = $this->client->get('/indexes/' . $uid . '/documents', [
                'fields' => 'id,groupId',
                'limit' => self::DOCUMENT_READ_BATCH_SIZE,
                'offset' => $offset,
            ]);

            $results = (array) ($response['results'] ?? []);

            if ($results === []) {
                break;
            }

            foreach ($results as $document) {
                yield [
                    'id' => (string) ($document['id'] ?? ''),
                    'groupId' => (string) ($document['groupId'] ?? ''),
                ];
            }

            $offset += count($results);
        }
    }

    /**
     * The same pairs as they are live in Meilisearch, read as a facet
     * distribution - every document contributes one entry per category it
     * carries, so the sum is comparable with what the source would produce.
     */
    protected function countLiveAssignments(string $uid): int
    {
        try {
            $response = $this->client->post('/indexes/' . $uid . '/search', [
                'q' => '',
                'limit' => 0,
                'facets' => ['categoryIds'],
            ]);
        } catch (MeiliException $exception) {
            return 0;
        }

        $distribution = $response['facetDistribution']['categoryIds'] ?? [];

        return is_array($distribution) ? (int) array_sum($distribution) : 0;
    }

    /**
     * Whether the source looks like it was read mid import. Only ever refuses
     * when something is already published - see MySqlIndexWriter.
     */
    protected function isImplausible(int $available, int $previous): bool
    {
        if ($previous === 0) {
            return false;
        }

        return $available < (int) ceil($previous * self::MIN_RETAINED_RATIO);
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    protected function pushPartialDocuments(string $uid, array $documents): void
    {
        // PUT is Meilisearch's *update*: it merges the fields it is given into
        // the existing document. POST is add-or-replace and would leave every
        // document holding nothing but its ID and its categories - the two
        // verbs are the other way round from what the words suggest.
        $task = $this->client->put(
            '/indexes/' . $uid . '/documents',
            $documents,
            ['primaryKey' => IndexSchema::PRIMARY_KEY]
        );

        $this->rememberTask($task);
    }

    /**
     * @param IndexDocument[] $documents
     */
    protected function writeScope(int $shopId, int $langId, array $documents): void
    {
        $uid = $this->swapMode
            ? $this->configuration->getShadowIndexUid($shopId, $langId)
            : $this->configuration->getIndexUid($shopId, $langId);

        $this->touchedScopes[$shopId . '_' . $langId] = ['shopId' => $shopId, 'langId' => $langId];
        $this->prepareIndex($uid, $shopId, $langId);

        $payload = [];

        foreach ($documents as $document) {
            $payload[] = $this->schema->toDocument($document);
        }

        // POST: add or replace. A rebuild writes the whole document, and
        // replacing is what makes a re-run of the same scope idempotent.
        $task = $this->client->post(
            '/indexes/' . $uid . '/documents',
            $payload,
            ['primaryKey' => IndexSchema::PRIMARY_KEY]
        );

        $this->rememberTask($task);
    }

    /**
     * Creates the index and brings its settings up to date, once per run.
     *
     * Settings are only pushed when they actually differ. Meilisearch treats a
     * settings change as a reason to rebuild the whole index, and the admin
     * reindex writes one batch per web request - pushing the same settings on
     * every tick would have the engine re-index everything fifty times over a
     * rebuild it is already doing once.
     *
     * When they do have to be pushed, they are queued before the documents that
     * follow them, and Meilisearch works its queue in order - so those documents
     * are indexed with the searchable and filterable attributes this scope
     * needs, without anything having to wait.
     */
    protected function prepareIndex(string $uid, int $shopId, int $langId): void
    {
        if (isset($this->preparedIndexes[$uid])) {
            return;
        }

        $this->createIndex($uid);
        $this->preparedIndexes[$uid] = true;

        $settings = $this->schema->buildSettings(
            $this->attributeConfiguration->getFacetAttributeIds($shopId),
            $this->synonymConfiguration->getActiveRules($shopId, $langId)
        );

        if (!$this->settingsDiffer($uid, $settings)) {
            return;
        }

        $this->rememberTask($this->client->patch('/indexes/' . $uid . '/settings', $settings));
    }

    /**
     * @param array<string, mixed> $desired
     */
    protected function settingsDiffer(string $uid, array $desired): bool
    {
        try {
            $current = $this->client->get('/indexes/' . $uid . '/settings');
        } catch (MeiliException $exception) {
            // Cannot tell - push, which is the safe direction.
            return true;
        }

        foreach ($desired as $key => $value) {
            $isOrdered = $key === 'searchableAttributes' || $key === 'rankingRules';

            if ($this->normalizeSetting($value, $isOrdered) !== $this->normalizeSetting($current[$key] ?? null, $isOrdered)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Settings compare as values, not as literal JSON: Meilisearch hands most of
     * them back as a set in whatever order it likes. Only the ranked lists -
     * searchable attributes and ranking rules - mean something by their order.
     */
    protected function normalizeSetting(mixed $value, bool $isOrdered): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $entry) {
            $normalized[$key] = $this->normalizeSetting($entry, false);
        }

        if (array_is_list($normalized)) {
            if (!$isOrdered) {
                sort($normalized);
            }

            return $normalized;
        }

        ksort($normalized);

        return $normalized;
    }

    protected function createIndex(string $uid): void
    {
        try {
            $task = $this->client->post('/indexes', [
                'uid' => $uid,
                'primaryKey' => IndexSchema::PRIMARY_KEY,
            ]);

            $this->rememberTask($task);
        } catch (MeiliException $exception) {
            // Creating an index that exists is the normal case on every run
            // after the first.
            if ($exception->getErrorCode() !== 'index_already_exists') {
                throw $exception;
            }
        }
    }

    /**
     * Puts every shadow index this run filled in place of its live counterpart.
     *
     * One call for all scopes: /swap-indexes takes a list and applies it as a
     * single task, so four subshops go live together rather than one at a time.
     */
    protected function swapIndexes(): void
    {
        if ($this->touchedScopes === []) {
            return;
        }

        $pairs = [];
        $shadowUids = [];

        foreach ($this->touchedScopes as $scope) {
            $liveUid = $this->configuration->getIndexUid($scope['shopId'], $scope['langId']);
            $shadowUid = $this->configuration->getShadowIndexUid($scope['shopId'], $scope['langId']);

            // A swap needs both sides to exist. On the very first rebuild the
            // live index does not, so it is created empty and swapped into the
            // shadow name, where it is dropped a moment later.
            $this->createIndex($liveUid);

            $pairs[] = ['indexes' => [$shadowUid, $liveUid]];
            $shadowUids[] = $shadowUid;
        }

        $this->waitForPendingTasks();
        $task = $this->client->post('/swap-indexes', $pairs);
        $this->client->waitForTask((int) ($task['taskUid'] ?? 0));

        foreach ($shadowUids as $shadowUid) {
            $this->deleteIndex($shadowUid);
        }
    }

    protected function clearScope(int $shopId, int $langId): void
    {
        $uid = $this->configuration->getIndexUid($shopId, $langId);

        if (!$this->indexExists($uid)) {
            return;
        }

        $task = $this->client->delete('/indexes/' . $uid . '/documents');
        $this->rememberTask($task);
    }

    /**
     * Removes shadow indexes left behind by a run that never committed.
     */
    protected function dropShadowIndexes(): void
    {
        foreach ($this->listIndexUids() as $uid) {
            if ($this->configuration->isShadowIndexUid($uid)) {
                $this->deleteIndex($uid);
            }
        }
    }

    /**
     * @return string[]
     */
    protected function listIndexUids(): array
    {
        try {
            $response = $this->client->get('/indexes', ['limit' => 1000]);
        } catch (MeiliException $exception) {
            return [];
        }

        $uids = [];
        $prefix = $this->configuration->getIndexPrefix();

        foreach ((array) ($response['results'] ?? []) as $index) {
            $uid = (string) ($index['uid'] ?? '');

            if ($uid !== '' && str_starts_with($uid, $prefix)) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    protected function deleteIndex(string $uid): void
    {
        try {
            $task = $this->client->delete('/indexes/' . $uid);
            $this->client->waitForTask((int) ($task['taskUid'] ?? 0));
        } catch (Throwable $exception) {
            // Deleting what is not there is the outcome that was wanted.
        }
    }

    protected function indexExists(string $uid): bool
    {
        try {
            $this->client->get('/indexes/' . $uid);
        } catch (MeiliException $exception) {
            return false;
        }

        return true;
    }

    protected function countDocuments(string $uid): int
    {
        try {
            $stats = $this->client->get('/indexes/' . $uid . '/stats');
        } catch (MeiliException $exception) {
            return 0;
        }

        return (int) ($stats['numberOfDocuments'] ?? 0);
    }

    /**
     * @param array<mixed> $task
     */
    protected function rememberTask(array $task): void
    {
        $this->lastTaskUid = max($this->lastTaskUid, (int) ($task['taskUid'] ?? 0));
    }

    protected function waitForPendingTasks(): void
    {
        if ($this->lastTaskUid > 0) {
            $this->client->waitForTask($this->lastTaskUid);
            $this->lastTaskUid = 0;
        }
    }

    /**
     * Documents of one write() call, split by the index they belong in.
     *
     * @param IndexDocument[] $documents
     *
     * @return array<string, array{shopId: int, langId: int, documents: IndexDocument[]}>
     */
    protected function groupByScope(array $documents): array
    {
        $groups = [];

        foreach ($documents as $document) {
            $key = $document->getShopId() . '_' . $document->getLangId();

            $groups[$key] ??= [
                'shopId' => $document->getShopId(),
                'langId' => $document->getLangId(),
                'documents' => [],
            ];

            $groups[$key]['documents'][] = $document;
        }

        return $groups;
    }

    protected function reset(): void
    {
        $this->started = false;
        $this->swapMode = false;
        $this->scopes = [];
        $this->touchedScopes = [];
        $this->preparedIndexes = [];
        $this->lastTaskUid = 0;
    }

    /*
     * The two shop touch points. Everything else this class does goes through
     * the injected MeiliClient, which is what makes the rebuild logic - what
     * is written where, in which order, and what is refused - checkable
     * without either a shop or a search server.
     */

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchRows(string $sql): array
    {
        return DatabaseHelper::fetchAll($sql);
    }

    protected function getViewName(string $table, int $langId, int $shopId): string
    {
        return Registry::get(TableViewNameGenerator::class)->getViewName($table, $langId, $shopId);
    }
}
