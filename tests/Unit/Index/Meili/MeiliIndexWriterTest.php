<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Index\Meili;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\SynonymConfiguration;
use foun10\EasySearch\Index\IndexDocument;
use foun10\EasySearch\Meili\IndexSchema;
use foun10\EasySearch\Meili\MeiliException;
use foun10\EasySearch\Tests\Unit\Double\SpyMeiliClient;
use foun10\EasySearch\Tests\Unit\Double\TestableMeiliConfiguration;
use foun10\EasySearch\Tests\Unit\Double\TestableMeiliIndexWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Writing the index into Meilisearch.
 *
 * The same two strategies as the MySql writer, in what a document store has
 * instead of tables: a full rebuild fills a shadow index per scope and swaps
 * the names in one call, a scoped rebuild clears that scope's index and
 * refills it. What has to be pinned is the same as there - order, and what the
 * class refuses to do - plus two things that are specific to talking to a
 * search server over HTTP: writes are enqueued rather than waited for, and
 * settings are only pushed when they actually differ, because Meilisearch
 * treats a settings change as a reason to re-index everything.
 *
 * The client is a transcript (SpyMeiliClient), so every assertion here is
 * about the requests the writer makes.
 */
class MeiliIndexWriterTest extends TestCase
{
    private SpyMeiliClient $client;

    private TestableMeiliIndexWriter $writer;

    /** @var array<string, mixed> */
    private array $settings = ['searchableAttributes' => ['title', 'brand'], 'filterableAttributes' => ['a', 'b']];

    private const LIVE = 'foun10easysearch_s1_l0';
    private const SHADOW = 'foun10easysearch_s1_l0_tmp';

    protected function setUp(): void
    {
        $this->client = new SpyMeiliClient();

        $schema = $this->createMock(IndexSchema::class);
        $schema->method('toDocument')->willReturnCallback(
            static fn (IndexDocument $document): array => [
                'id' => $document->getId(),
                'groupId' => $document->getGroupId(),
            ]
        );
        $schema->method('buildSettings')->willReturnCallback(fn (): array => $this->settings);

        $this->writer = new TestableMeiliIndexWriter(
            $this->client,
            new TestableMeiliConfiguration(),
            $schema,
            $this->createMock(AttributeConfiguration::class),
            $this->createMock(SynonymConfiguration::class)
        );
    }

    private function document(
        string $id = 'doc-1',
        int $shopId = 1,
        int $langId = 0,
        string $articleId = 'a-1',
        string $groupId = 'a-1'
    ): IndexDocument {
        return new IndexDocument(
            $id,
            $shopId,
            $langId,
            $articleId,
            '',
            $groupId,
            'Titel',
            '4711',
            '',
            '',
            'Marke',
            'm-1',
            [],
            [],
            'search text',
            'boost text',
            19.99,
            3.0,
            7,
            '2024-01-05',
            true,
            []
        );
    }

    /**
     * @return array<int, array{shopId: int, langId: int}>
     */
    private function scope(int $shopId = 1, int $langId = 0): array
    {
        return [['shopId' => $shopId, 'langId' => $langId]];
    }

    /**
     * Scripts what an index answers when it is paged through.
     *
     * @param array<int, array{id: string, groupId: string}> $documents
     */
    private function indexHolds(string $uid, array $documents): void
    {
        $this->client->answers['GET /indexes/' . $uid . '/documents'] =
            static function (array $call) use ($documents): array {
                return [
                    'results' => array_slice(
                        $documents,
                        (int) ($call['query']['offset'] ?? 0),
                        (int) ($call['query']['limit'] ?? 5000)
                    ),
                ];
            };
    }

    private function indexIsMissing(string $uid): void
    {
        $this->client->answers['GET /indexes/' . $uid] = new MeiliException('Index not found', 404);
    }

    // ---------------------------------------------------------------
    // starting a run
    // ---------------------------------------------------------------

    /**
     * A run that died before its swap leaves shadow indexes behind, and
     * refilling one that still holds documents would mix two catalogues.
     */
    public function testAFullRebuildThrowsAwayShadowIndexesLeftBehind(): void
    {
        $this->client->answers['GET /indexes'] = [
            'results' => [
                ['uid' => self::LIVE],
                ['uid' => self::SHADOW],
                ['uid' => 'foun10easysearch_s2_l0_tmp'],
            ],
        ];

        $this->writer->begin();

        $this->assertSame(
            [
                'GET /indexes',
                'DELETE /indexes/' . self::SHADOW,
                'WAIT 1',
                'DELETE /indexes/foun10easysearch_s2_l0_tmp',
                'WAIT 2',
            ],
            $this->client->trace(),
            'the live indexes keep serving'
        );
        $this->assertSame(
            ['limit' => 1000],
            $this->client->firstCallTo('GET', '/indexes')['query'],
            'without a limit the server answers with its default page of 20'
        );
    }

    /**
     * Another installation may share the Meilisearch instance - that is what
     * the prefix is for, and it has to be respected in both directions.
     */
    public function testIndexesOfAnotherInstallationAreLeftAlone(): void
    {
        $this->client->answers['GET /indexes'] = [
            'results' => [['uid' => 'someoneelse_s1_l0_tmp'], ['uid' => self::SHADOW]],
        ];

        $this->writer->begin();

        $this->assertSame(['DELETE /indexes/' . self::SHADOW, 'WAIT 1'], array_slice($this->client->trace(), 1));
    }

    public function testAnUnreachableServerDoesNotStopTheRunFromStarting(): void
    {
        $this->client->answers['GET /indexes'] = new MeiliException('Connection refused', 0);

        $this->writer->begin();

        $this->assertSame(['GET /indexes'], $this->client->trace());
    }

    /**
     * A scoped rebuild cannot swap - that would leave the scopes it did not
     * rebuild pointing at nothing - so it clears its own scope instead.
     */
    public function testAScopedRebuildClearsItsScopeInsteadOfSwapping(): void
    {
        $this->writer->begin($this->scope());

        $this->assertSame(
            ['GET /indexes/' . self::LIVE, 'DELETE /indexes/' . self::LIVE . '/documents'],
            $this->client->trace()
        );
    }

    public function testAScopeWithoutAnIndexHasNothingToClear(): void
    {
        $this->indexIsMissing(self::LIVE);

        $this->writer->begin($this->scope());

        $this->assertSame(['GET /indexes/' . self::LIVE], $this->client->trace());
    }

    public function testAStartedRunCannotBeStartedAgain(): void
    {
        $this->writer->begin();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already started');

        $this->writer->begin();
    }

    public function testWritingWithoutStartingIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not started');

        $this->writer->write([$this->document()]);
    }

    public function testCommittingWithoutStartingIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not started');

        $this->writer->commit();
    }

    /**
     * Resuming touches nothing: the shadow index an earlier request created is
     * sitting there under a name this object derives again.
     */
    public function testResumingIsFreeAndKeepsWritingIntoTheShadowIndex(): void
    {
        $this->writer->resume();
        $this->writer->write([$this->document()]);

        $this->assertNotContains('GET /indexes', $this->client->trace());
        $this->assertNotNull($this->client->firstCallTo('POST', '/indexes/' . self::SHADOW . '/documents'));
    }

    public function testResumingAScopedRunKeepsWritingIntoTheLiveIndex(): void
    {
        $this->writer->resume($this->scope());
        $this->writer->write([$this->document()]);

        $this->assertNotNull($this->client->firstCallTo('POST', '/indexes/' . self::LIVE . '/documents'));
    }

    // ---------------------------------------------------------------
    // writing documents
    // ---------------------------------------------------------------

    public function testAnEmptyBatchIsNoRequest(): void
    {
        $this->writer->begin($this->scope());
        $this->client->calls = [];

        $this->writer->write([]);

        $this->assertSame([], $this->client->calls);
    }

    /**
     * A rebuild writes whole documents, and replacing is what makes re-running
     * the same scope idempotent - so POST (add or replace), not PUT.
     */
    public function testDocumentsAreAddedOrReplacedWithTheirPrimaryKey(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document(id: 'doc-1'), $this->document(id: 'doc-2')]);

        $call = $this->client->firstCallTo('POST', '/indexes/' . self::SHADOW . '/documents');

        $this->assertNotNull($call);
        $this->assertSame(['primaryKey' => 'id'], $call['query']);
        $this->assertSame(
            [['id' => 'doc-1', 'groupId' => 'a-1'], ['id' => 'doc-2', 'groupId' => 'a-1']],
            $call['payload']
        );
    }

    /**
     * One index holds exactly one shop and language, so a mixed batch is split
     * before it is sent.
     */
    public function testAMixedBatchIsSplitByShopAndLanguage(): void
    {
        $this->writer->begin();
        $this->writer->write([
            $this->document(id: 'doc-1', shopId: 1, langId: 0),
            $this->document(id: 'doc-2', shopId: 2, langId: 1),
            $this->document(id: 'doc-3', shopId: 1, langId: 0),
        ]);

        $first = $this->client->firstCallTo('POST', '/indexes/' . self::SHADOW . '/documents');
        $second = $this->client->firstCallTo('POST', '/indexes/foun10easysearch_s2_l1_tmp/documents');

        $this->assertSame(['doc-1', 'doc-3'], array_column((array) $first['payload'], 'id'));
        $this->assertSame(['doc-2'], array_column((array) $second['payload'], 'id'));
    }

    public function testTheIndexIsCreatedWithItsPrimaryKey(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document()]);

        $this->assertSame(
            ['uid' => self::SHADOW, 'primaryKey' => 'id'],
            $this->client->firstCallTo('POST', '/indexes')['payload']
        );
    }

    /**
     * The admin rebuild writes one batch per web request. Preparing the index
     * on every one of them would have Meilisearch re-index everything over and
     * over, so it happens once per run.
     */
    public function testAnIndexIsPreparedOncePerRun(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document(id: 'doc-1')]);
        $this->writer->write([$this->document(id: 'doc-2')]);

        $this->assertCount(1, $this->client->callsTo('POST', '/indexes'));
        $this->assertCount(1, $this->client->callsTo('PATCH', '/indexes/' . self::SHADOW . '/settings'));
        $this->assertCount(2, $this->client->callsTo('POST', '/indexes/' . self::SHADOW . '/documents'));
    }

    /**
     * Creating an index that exists is the normal case on every run after the
     * first.
     */
    public function testAnIndexThatAlreadyExistsIsNotAnError(): void
    {
        $this->client->answers['POST /indexes'] = new MeiliException('exists', 409, 'index_already_exists');

        $this->writer->begin();
        $this->writer->write([$this->document()]);

        $this->assertNotNull($this->client->firstCallTo('POST', '/indexes/' . self::SHADOW . '/documents'));
    }

    public function testAnyOtherFailureToCreateAnIndexIsReported(): void
    {
        $this->client->answers['POST /indexes'] = new MeiliException('bad uid', 400, 'invalid_index_uid');

        $this->writer->begin();

        $this->expectException(MeiliException::class);
        $this->expectExceptionMessage('bad uid');

        $this->writer->write([$this->document()]);
    }

    // ---------------------------------------------------------------
    // settings
    // ---------------------------------------------------------------

    public function testSettingsArePushedWhenTheIndexDoesNotHaveThemYet(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document()]);

        $this->assertSame(
            $this->settings,
            $this->client->firstCallTo('PATCH', '/indexes/' . self::SHADOW . '/settings')['payload']
        );
    }

    /**
     * Meilisearch hands most settings back as a set in whatever order it
     * likes, so they are compared as values. Pushing them again would have the
     * engine re-index the whole catalogue for nothing.
     */
    public function testSettingsThatOnlyDifferInOrderAreNotPushed(): void
    {
        $this->client->answers['GET /indexes/' . self::SHADOW . '/settings'] = [
            'filterableAttributes' => ['b', 'a'],
            'searchableAttributes' => ['title', 'brand'],
            'somethingElse' => ['not ours'],
        ];

        $this->writer->begin();
        $this->writer->write([$this->document()]);

        $this->assertSame([], $this->client->callsTo('PATCH', '/indexes/' . self::SHADOW . '/settings'));
    }

    /**
     * ... but the ranked lists mean something by their order, so a reordering
     * there is a real change.
     */
    public function testReorderedSearchableAttributesArePushed(): void
    {
        $this->client->answers['GET /indexes/' . self::SHADOW . '/settings'] = [
            'filterableAttributes' => ['a', 'b'],
            'searchableAttributes' => ['brand', 'title'],
        ];

        $this->writer->begin();
        $this->writer->write([$this->document()]);

        $this->assertCount(1, $this->client->callsTo('PATCH', '/indexes/' . self::SHADOW . '/settings'));
    }

    /**
     * The comparison reaches into nested values too - synonyms come back as a
     * map of word to replacements, and the replacements are a set like any
     * other list.
     */
    public function testNestedListsAreComparedAsSetsAsWell(): void
    {
        $this->settings = ['synonyms' => ['bh' => ['bustier', 'korsage'], 'slip' => ['panty']]];
        $this->client->answers['GET /indexes/' . self::SHADOW . '/settings'] = [
            // The same synonyms, with both the map and its lists in another
            // order - which is all Meilisearch promises.
            'synonyms' => ['slip' => ['panty'], 'bh' => ['korsage', 'bustier']],
        ];

        $this->writer->begin();
        $this->writer->write([$this->document()]);

        $this->assertSame([], $this->client->callsTo('PATCH', '/indexes/' . self::SHADOW . '/settings'));
    }

    public function testAChangedValueIsPushed(): void
    {
        $this->client->answers['GET /indexes/' . self::SHADOW . '/settings'] = [
            'filterableAttributes' => ['a'],
            'searchableAttributes' => ['title', 'brand'],
        ];

        $this->writer->begin();
        $this->writer->write([$this->document()]);

        $this->assertCount(1, $this->client->callsTo('PATCH', '/indexes/' . self::SHADOW . '/settings'));
    }

    /**
     * If the current settings cannot be read there is no way to tell, and
     * pushing is the safe direction.
     */
    public function testUnreadableSettingsArePushedAnyway(): void
    {
        $this->client->answers['GET /indexes/' . self::SHADOW . '/settings'] =
            new MeiliException('gone', 404);

        $this->writer->begin();
        $this->writer->write([$this->document()]);

        $this->assertCount(1, $this->client->callsTo('PATCH', '/indexes/' . self::SHADOW . '/settings'));
    }

    /**
     * Settings are queued before the documents that follow them, and
     * Meilisearch works its queue in order - so those documents are indexed
     * with the attributes this scope needs, without anything having to wait.
     */
    public function testSettingsAreQueuedBeforeTheDocumentsTheyApplyTo(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document()]);

        $this->assertSame(
            [
                'POST /indexes',
                'GET /indexes/' . self::SHADOW . '/settings',
                'PATCH /indexes/' . self::SHADOW . '/settings',
                'POST /indexes/' . self::SHADOW . '/documents',
            ],
            array_slice($this->client->trace(), 1)
        );
    }

    // ---------------------------------------------------------------
    // waiting, committing, rolling back
    // ---------------------------------------------------------------

    /**
     * Meilisearch applies its queue in order, so waiting after every batch
     * would serialise the whole rebuild against the indexer.
     */
    public function testWritingDoesNotWaitForTheIndexer(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document()]);

        $this->assertSame([], $this->client->waitedFor);
    }

    /**
     * One wait, for the last task - which implies every earlier one.
     */
    public function testCommitWaitsOnceForTheLastTask(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([$this->document(id: 'doc-1')]);
        $this->writer->write([$this->document(id: 'doc-2')]);

        $lastTask = $this->client->nextTaskUid - 1;
        $this->writer->commit();

        $this->assertSame([$lastTask], $this->client->waitedFor);
    }

    /**
     * All scopes in one /swap-indexes call, so four subshops go live together
     * rather than one after another.
     */
    public function testEveryFilledShadowIndexGoesLiveInOneCall(): void
    {
        $this->writer->begin();
        $this->writer->write([
            $this->document(id: 'doc-1', shopId: 1, langId: 0),
            $this->document(id: 'doc-2', shopId: 2, langId: 0),
        ]);
        $this->writer->commit();

        $this->assertSame(
            [
                ['indexes' => [self::SHADOW, self::LIVE]],
                ['indexes' => ['foun10easysearch_s2_l0_tmp', 'foun10easysearch_s2_l0']],
            ],
            $this->client->firstCallTo('POST', '/swap-indexes')['payload']
        );
    }

    /**
     * A swap needs both sides to exist, and on the very first rebuild the live
     * index does not.
     */
    public function testTheLiveIndexIsCreatedIfTheScopeWasNeverIndexed(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document()]);
        $this->writer->commit();

        $this->assertSame(
            [['uid' => self::SHADOW, 'primaryKey' => 'id'], ['uid' => self::LIVE, 'primaryKey' => 'id']],
            array_column($this->client->callsTo('POST', '/indexes'), 'payload')
        );
    }

    public function testTheShadowIndexIsRemovedAfterTheSwap(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document()]);
        $this->writer->commit();

        $trace = $this->client->trace();

        $this->assertLessThan(
            array_search('DELETE /indexes/' . self::SHADOW, $trace, true),
            array_search('POST /swap-indexes', $trace, true),
            'the swap happens first - the shadow name holds the retired index afterwards'
        );
    }

    public function testTheSwapIsWaitedForBeforeAnythingIsDeleted(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document()]);
        $this->writer->commit();

        $this->assertNotEmpty($this->client->waitedFor);
        $this->assertContains(
            'POST /swap-indexes',
            array_slice($this->client->trace(), 0, (int) array_search(
                'DELETE /indexes/' . self::SHADOW,
                $this->client->trace(),
                true
            ))
        );
    }

    public function testAScopedRunNeverSwaps(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([$this->document()]);
        $this->writer->commit();

        $this->assertSame([], $this->client->callsTo('POST', '/swap-indexes'));
    }

    public function testARunThatWroteNothingSwapsNothing(): void
    {
        $this->writer->begin();
        $this->writer->commit();

        $this->assertSame([], $this->client->callsTo('POST', '/swap-indexes'));
        $this->assertSame([], $this->client->waitedFor, 'and waits for no task either');
    }

    /**
     * The whole commit, in order. Two waits carry it: the documents have to be
     * indexed and the live index has to exist before the swap, and the swap
     * has to have happened before the shadow index - which now holds the
     * retired one - is thrown away.
     */
    public function testTheCommitSequenceIsWaitSwapWaitDrop(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document()]);
        $this->client->calls = [];

        $this->writer->commit();

        $this->assertSame(
            [
                'WAIT 3',
                'POST /indexes',
                'WAIT 4',
                'POST /swap-indexes',
                'WAIT 5',
                'DELETE /indexes/' . self::SHADOW,
                'WAIT 6',
            ],
            $this->client->trace()
        );
    }

    /**
     * Shop 1 in language 12 and shop 11 in language 2 are different scopes,
     * and would be the same one if the key were the two numbers written next
     * to each other.
     */
    public function testScopesThatWriteTheSameDigitsStayApart(): void
    {
        $this->writer->begin();
        $this->writer->write([
            $this->document(id: 'doc-1', shopId: 1, langId: 12),
            $this->document(id: 'doc-2', shopId: 11, langId: 2),
        ]);
        $this->writer->commit();

        $this->assertSame(
            ['doc-1'],
            array_column(
                (array) $this->client->firstCallTo('POST', '/indexes/foun10easysearch_s1_l12_tmp/documents')['payload'],
                'id'
            )
        );
        $this->assertSame(
            ['doc-2'],
            array_column(
                (array) $this->client->firstCallTo('POST', '/indexes/foun10easysearch_s11_l2_tmp/documents')['payload'],
                'id'
            )
        );
        $this->assertCount(2, (array) $this->client->firstCallTo('POST', '/swap-indexes')['payload']);
    }

    /**
     * Two languages of one shop are two indexes - the shop alone does not
     * identify a scope.
     */
    public function testTwoLanguagesOfOneShopAreTwoIndexes(): void
    {
        $this->writer->begin();
        $this->writer->write([
            $this->document(id: 'doc-1', shopId: 1, langId: 0),
            $this->document(id: 'doc-2', shopId: 1, langId: 1),
        ]);
        $this->writer->commit();

        $this->assertNotNull($this->client->firstCallTo('POST', '/indexes/' . self::SHADOW . '/documents'));
        $this->assertNotNull($this->client->firstCallTo('POST', '/indexes/foun10easysearch_s1_l1_tmp/documents'));
        $this->assertSame(
            [
                ['indexes' => [self::SHADOW, self::LIVE]],
                ['indexes' => ['foun10easysearch_s1_l1_tmp', 'foun10easysearch_s1_l1']],
            ],
            $this->client->firstCallTo('POST', '/swap-indexes')['payload']
        );
    }

    public function testTheRunIsOverAfterACommit(): void
    {
        $this->writer->begin();
        $this->writer->commit();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not started');

        $this->writer->write([$this->document()]);
    }

    public function testRollbackThrowsAwayTheShadowIndexesItFilled(): void
    {
        $this->writer->begin();
        $this->writer->write([$this->document()]);
        $this->client->calls = [];

        $this->writer->rollback();

        $this->assertSame(['DELETE /indexes/' . self::SHADOW, 'WAIT 4'], $this->client->trace());
    }

    /**
     * A scoped run cleared its index before the first batch, so there is
     * nothing to roll back to - and deleting anything would make it worse.
     */
    public function testRollingBackAScopedRunDeletesNothing(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([$this->document()]);
        $this->client->calls = [];

        $this->writer->rollback();

        $this->assertSame([], $this->client->calls);
    }

    public function testTheRunIsOverAfterARollback(): void
    {
        $this->writer->begin();
        $this->writer->rollback();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not started');

        $this->writer->write([$this->document()]);
    }

    // ---------------------------------------------------------------
    // incremental delete
    // ---------------------------------------------------------------

    public function testDeletingAnArticleRemovesTheDocumentOfThatScope(): void
    {
        $this->writer->delete('a-1', 1, 0);

        $this->assertSame(
            ['DELETE /indexes/' . self::LIVE . '/documents/' . md5('a-1_1_0')],
            $this->client->trace()
        );
    }

    public function testDeletingSomethingThatIsNotIndexedIsFine(): void
    {
        $this->client->answers['DELETE /indexes/' . self::LIVE . '/documents/' . md5('a-1_1_0')] =
            new MeiliException('unknown document', 404);

        $this->writer->delete('a-1', 1, 0);

        $this->assertCount(1, $this->client->calls);
    }

    public function testAServerErrorOnDeleteIsReported(): void
    {
        $this->client->answers['DELETE /indexes/' . self::LIVE . '/documents/' . md5('a-1_1_0')] =
            new MeiliException('internal error', 500);

        $this->expectException(MeiliException::class);

        $this->writer->delete('a-1', 1, 0);
    }

    // ---------------------------------------------------------------
    // clearing a scope
    // ---------------------------------------------------------------

    public function testClearingAScopeReportsWhatItRemoved(): void
    {
        $this->client->answers['GET /indexes/' . self::LIVE . '/stats'] = ['numberOfDocuments' => 1200];

        $this->assertSame(1200, $this->writer->clearScopeBatch(1, 0, 500));
        $this->assertNotNull($this->client->firstCallTo('DELETE', '/indexes/' . self::LIVE . '/documents'));
        $this->assertNotEmpty($this->client->waitedFor, 'the caller asks again right afterwards');
    }

    public function testAnEmptyIndexEndsTheCallersLoop(): void
    {
        $this->assertSame(0, $this->writer->clearScopeBatch(1, 0, 500));
        $this->assertSame(['GET /indexes/' . self::LIVE . '/stats'], $this->client->trace());
    }

    // ---------------------------------------------------------------
    // deriving the category assignments
    // ---------------------------------------------------------------

    public function testNothingIndexedMeansNothingToDerive(): void
    {
        $this->indexIsMissing(self::LIVE);

        $result = $this->writer->rebuildCategories(1, 0);

        $this->assertTrue($result->isPublished());
        $this->assertSame(0, $result->getWritten());
        $this->assertSame(0, $result->getPrevious());
        $this->assertSame([], $this->writer->queries, 'the shop was not even asked');
    }

    /**
     * Which documents exist is asked of Meilisearch, not of the shop: a
     * partial update would otherwise create a thin document for an article
     * added since the last rebuild.
     */
    public function testEveryIndexedDocumentIsUpdatedWithItsGroupsCategories(): void
    {
        $this->indexHolds(self::LIVE, [
            ['id' => 'doc-1', 'groupId' => 'p-1'],
            ['id' => 'doc-2', 'groupId' => 'p-2'],
        ]);
        $this->writer->assignmentRows = [
            ['OXOBJECTID' => 'p-1', 'OXCATNID' => 'c-1'],
            ['OXOBJECTID' => 'p-1', 'OXCATNID' => 'c-2'],
        ];

        $result = $this->writer->rebuildCategories(1, 0);

        $call = $this->client->firstCallTo('PUT', '/indexes/' . self::LIVE . '/documents');

        $this->assertSame(['primaryKey' => 'id'], $call['query']);
        $this->assertSame(
            [
                ['id' => 'doc-1', 'categoryIds' => ['c-1', 'c-2']],
                // Sent even though it is in no category at all: a partial
                // update only touches what it names, so leaving this one out
                // would keep it listed where it no longer belongs.
                ['id' => 'doc-2', 'categoryIds' => []],
            ],
            $call['payload']
        );
        $this->assertSame(2, $result->getWritten(), 'pairs, not documents');
    }

    public function testTheShopIsReadOnceForTheWholeScope(): void
    {
        $this->indexHolds(self::LIVE, [['id' => 'doc-1', 'groupId' => 'p-1']]);

        $this->writer->rebuildCategories(1, 2);

        $this->assertSame(
            ['SELECT OXOBJECTID, OXCATNID FROM oxv_oxobject2category_1_2'],
            $this->writer->queries
        );
    }

    public function testTheIndexIsPagedThroughRatherThanReadAtOnce(): void
    {
        $documents = [];

        for ($i = 0; $i < 12000; $i++) {
            $documents[] = ['id' => 'doc-' . $i, 'groupId' => 'p-' . $i];
        }

        $this->indexHolds(self::LIVE, $documents);

        $this->writer->rebuildCategories(1, 0);

        $reads = $this->client->callsTo('GET', '/indexes/' . self::LIVE . '/documents');

        $this->assertSame(
            [0, 5000, 10000, 12000],
            array_column(array_column(array_slice($reads, 0, 4), 'query'), 'offset')
        );
        $this->assertSame('id,groupId', $reads[0]['query']['fields'], 'only what the rebuild needs');
    }

    public function testLargeUpdatesAreSentInBatches(): void
    {
        $documents = [];

        for ($i = 0; $i < 2001; $i++) {
            $documents[] = ['id' => 'doc-' . $i, 'groupId' => 'p-' . $i];
        }

        $this->indexHolds(self::LIVE, $documents);

        $this->writer->rebuildCategories(1, 0);

        $updates = $this->client->callsTo('PUT', '/indexes/' . self::LIVE . '/documents');

        $this->assertCount(2, $updates);
        $this->assertCount(2000, (array) $updates[0]['payload']);
        $this->assertCount(1, (array) $updates[1]['payload']);
    }

    /**
     * Rebuilding while the ERP import has oxobject2category truncated would
     * empty every category page in the shop.
     */
    public function testASourceThatLostMostOfItsPairsIsRefused(): void
    {
        $this->indexHolds(self::LIVE, [['id' => 'doc-1', 'groupId' => 'p-1']]);
        $this->writer->assignmentRows = [['OXOBJECTID' => 'p-1', 'OXCATNID' => 'c-1']];
        $this->client->answers['POST /indexes/' . self::LIVE . '/search'] = [
            'facetDistribution' => ['categoryIds' => ['c-1' => 60, 'c-2' => 40]],
        ];

        $result = $this->writer->rebuildCategories(1, 0);

        $this->assertFalse($result->isPublished());
        $this->assertSame(1, $result->getAvailable());
        $this->assertSame(100, $result->getPrevious());
        $this->assertSame([], $this->client->callsTo('PUT', '/indexes/' . self::LIVE . '/documents'));
        $this->assertSame(
            ['q' => '', 'limit' => 0, 'facets' => ['categoryIds']],
            $this->client->firstCallTo('POST', '/indexes/' . self::LIVE . '/search')['payload'],
            'the counts come from the facet distribution, so no documents are asked for'
        );
    }

    /**
     * A server that answers something other than a distribution has nothing to
     * compare against, and a rebuild that refuses forever is worse than one
     * that goes ahead.
     */
    public function testAnUnexpectedAnswerCountsAsNothingLive(): void
    {
        $this->indexHolds(self::LIVE, [['id' => 'doc-1', 'groupId' => 'p-1']]);
        $this->client->answers['POST /indexes/' . self::LIVE . '/search'] = [
            'facetDistribution' => ['categoryIds' => 'not a distribution'],
        ];

        $result = $this->writer->rebuildCategories(1, 0);

        $this->assertTrue($result->isPublished());
        $this->assertSame(0, $result->getPrevious());
    }

    /**
     * @dataProvider plausibilityProvider
     */
    public function testWhereTheLineIsDrawn(int $available, int $previous, bool $expected): void
    {
        $documents = [];
        $assignments = [];

        for ($i = 0; $i < $available; $i++) {
            $documents[] = ['id' => 'doc-' . $i, 'groupId' => 'p-' . $i];
            $assignments[] = ['OXOBJECTID' => 'p-' . $i, 'OXCATNID' => 'c-1'];
        }

        $this->indexHolds(self::LIVE, $documents);
        $this->writer->assignmentRows = $assignments;
        $this->client->answers['POST /indexes/' . self::LIVE . '/search'] = [
            'facetDistribution' => ['categoryIds' => ['c-1' => $previous]],
        ];

        $this->assertSame($expected, $this->writer->rebuildCategories(1, 0)->isPublished());
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public function plausibilityProvider(): array
    {
        return [
            'exactly half is still plausible' => [50, 100, true],
            'one below half is not' => [49, 100, false],
            'more than before' => [120, 100, true],
            'the first run publishes an empty source' => [0, 0, true],
            'half of an odd count rounds up' => [50, 101, false],
        ];
    }

    public function testForcePublishesEvenAnImplausibleSource(): void
    {
        $this->indexHolds(self::LIVE, [['id' => 'doc-1', 'groupId' => 'p-1']]);
        $this->client->answers['POST /indexes/' . self::LIVE . '/search'] = [
            'facetDistribution' => ['categoryIds' => ['c-1' => 100]],
        ];

        $this->assertTrue($this->writer->rebuildCategories(1, 0, true)->isPublished());
        $this->assertCount(1, $this->client->callsTo('PUT', '/indexes/' . self::LIVE . '/documents'));
    }

    /**
     * A server that cannot answer what is live has nothing to protect, so the
     * rebuild goes ahead rather than refusing forever.
     */
    public function testAnUnreadableLiveCountDoesNotBlockTheRebuild(): void
    {
        $this->indexHolds(self::LIVE, [['id' => 'doc-1', 'groupId' => 'p-1']]);
        $this->client->answers['POST /indexes/' . self::LIVE . '/search'] = new MeiliException('nope', 500);

        $result = $this->writer->rebuildCategories(1, 0);

        $this->assertTrue($result->isPublished());
        $this->assertSame(0, $result->getPrevious());
    }

    public function testTheUpdatesAreWaitedForBeforeTheResultIsReported(): void
    {
        $this->indexHolds(self::LIVE, [['id' => 'doc-1', 'groupId' => 'p-1']]);

        $this->writer->rebuildCategories(1, 0);

        $this->assertNotEmpty($this->client->waitedFor);
    }
}
