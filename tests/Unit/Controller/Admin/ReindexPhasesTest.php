<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Controller\Admin;

use foun10\EasySearch\Controller\Admin\Reindex;
use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Index\DictionaryBuilder;
use foun10\EasySearch\Index\DocumentProvider;
use foun10\EasySearch\Index\IndexDocument;
use foun10\EasySearch\Index\IndexWriterInterface;
use foun10\EasySearch\Index\RebuildResult;
use foun10\EasySearch\Tests\Unit\Double\SpyIndexWriter;
use foun10\EasySearch\Tests\Unit\Double\TestableReindexPhases;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The browser-driven rebuild.
 *
 * A web request cannot rebuild a large catalogue, so the browser walks it one
 * tick at a time and the cursor lives in the client. That design decides what
 * matters here: the endpoint is stateless, so **every** number that steers a
 * run - the cursor, the running total, the batch size, the language - arrives
 * from the client on each tick and is therefore attacker-controlled in shape as
 * well as in value.
 *
 * So the tests below are about two things. What each phase does with a good
 * request, and what it does with a request that is not: an array where a number
 * was meant used to become the integer 1 without a warning, which quietly moved
 * a rebuild to another language.
 *
 * The second theme is that a failed tick has to reach the browser as JSON. The
 * client drives the loop; an exception that escapes leaves it waiting on a
 * response that never says what went wrong.
 */
class ReindexPhasesTest extends TestCase
{
    private TestableReindexPhases $host;

    private SpyIndexWriter $writer;

    /** @var array<int, array{shopId: int, langId: int, lastId: string, batchSize: int}> */
    private array $provideCalls = [];

    /** @var array<string, int> Article counts keyed "shopId_langId" */
    private array $articleCounts = [];

    /** @var array<int, IndexDocument[]> One batch per provideBatch() call, in order */
    private array $batches = [];

    private string $nextLastId = '';

    /** @var array<int, int[]> Active language IDs per shop */
    private array $activeLanguages = [1 => [0, 1]];

    /** @var array<int, array{shopId: int, langId: int}> */
    private array $dictionaryCalls = [];

    private int $dictionaryTerms = 0;

    protected function setUp(): void
    {
        $this->writer = new SpyIndexWriter();

        $provider = $this->createMock(DocumentProvider::class);
        $provider->method('provideBatch')->willReturnCallback(
            function (int $shopId, int $langId, string $lastId, int $batchSize): array {
                $this->provideCalls[] = [
                    'shopId' => $shopId,
                    'langId' => $langId,
                    'lastId' => $lastId,
                    'batchSize' => $batchSize,
                ];

                return [
                    'documents' => array_shift($this->batches) ?? [],
                    'lastId' => $this->nextLastId,
                ];
            }
        );
        $provider->method('countArticles')->willReturnCallback(
            fn (int $shopId, int $langId): int => $this->articleCounts[$shopId . '_' . $langId] ?? 0
        );

        $dictionaryBuilder = $this->createMock(DictionaryBuilder::class);
        $dictionaryBuilder->method('build')->willReturnCallback(
            function (int $shopId, int $langId): int {
                $this->dictionaryCalls[] = ['shopId' => $shopId, 'langId' => $langId];

                return $this->dictionaryTerms;
            }
        );

        $shopLanguages = $this->createMock(ShopLanguages::class);
        $shopLanguages->method('getActiveIds')->willReturnCallback(
            fn (?int $shopId = null): array => $this->activeLanguages[$shopId] ?? []
        );

        $this->host = new TestableReindexPhases();
        $this->host->services = [
            IndexWriterInterface::class => $this->writer,
            DocumentProvider::class => $provider,
            DictionaryBuilder::class => $dictionaryBuilder,
            ShopLanguages::class => $shopLanguages,
        ];
    }

    /**
     * One tick, with the request the browser would have sent.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed> The payload the browser got back
     */
    private function tick(array $parameters = []): array
    {
        $this->host->request->escaped = $parameters;
        $this->host->reindexTick();

        return $this->host->lastPayload();
    }

    /**
     * @return IndexDocument[]
     */
    private function documents(int $count): array
    {
        $documents = [];

        for ($i = 0; $i < $count; $i++) {
            $documents[] = $this->createMock(IndexDocument::class);
        }

        return $documents;
    }

    // ---------------------------------------------------------------
    // which phase a tick runs
    // ---------------------------------------------------------------

    public function testTheClearPhaseIsRunWhenTheBrowserAsksForIt(): void
    {
        $payload = $this->tick(['phase' => 'clear']);

        $this->assertSame('clear', $payload['phase']);
        $this->assertSame(['clear'], $this->writer->steps);
    }

    public function testTheCategoryPhaseIsRunWhenTheBrowserAsksForIt(): void
    {
        $payload = $this->tick(['phase' => 'category']);

        $this->assertSame('category', $payload['phase']);
        $this->assertSame(['categories'], $this->writer->steps);
    }

    public function testTheDictionaryPhaseIsRunWhenTheBrowserAsksForIt(): void
    {
        $payload = $this->tick(['phase' => 'dictionary']);

        $this->assertSame('dictionary', $payload['phase']);
        $this->assertSame([], $this->writer->steps);
    }

    public function testTheIndexPhaseIsRunWhenTheBrowserAsksForIt(): void
    {
        $payload = $this->tick(['phase' => 'index']);

        $this->assertSame('index', $payload['phase']);
    }

    /**
     * Indexing is the default rather than an error, because it is the phase a
     * tick spends nearly all of its ticks in.
     */
    public function testAnythingUnrecognisedIndexes(): void
    {
        $this->assertSame('index', $this->tick(['phase' => 'nonsense'])['phase']);
        $this->assertSame('index', $this->tick([])['phase']);
        $this->assertSame('index', $this->tick(['phase' => ''])['phase']);
    }

    public function testEveryFinishedTickTellsTheBrowserItWorked(): void
    {
        $this->assertTrue($this->tick(['phase' => 'clear'])['ok']);
        $this->assertTrue($this->tick(['phase' => 'category'])['ok']);
        $this->assertTrue($this->tick(['phase' => 'dictionary'])['ok']);
        $this->assertTrue($this->tick(['phase' => 'index'])['ok']);
    }

    /**
     * Only the shop being edited is rebuilt. A shop id from the request would
     * let the admin screen of one subshop rewrite another's index.
     */
    public function testTheScopeIsTheShopBeingEditedAndNotOneFromTheRequest(): void
    {
        $this->host->editShopId = 3;

        $this->tick(['phase' => 'clear', 'shopId' => 99, 'langId' => 2]);

        $this->assertSame(
            [['shopId' => 3, 'langId' => 2, 'limit' => 5000]],
            $this->writer->clearBatches
        );
    }

    // ---------------------------------------------------------------
    // a request whose parameters are not the shape they should be
    // ---------------------------------------------------------------

    /**
     * `?langId[]=1` used to cast to the integer 1 with no warning at all, so a
     * rebuild silently ran against the wrong language.
     */
    public function testAnArrayLanguageIsNotSilentlyLanguageOne(): void
    {
        $this->tick(['phase' => 'clear', 'langId' => ['1']]);

        $this->assertSame(0, $this->writer->clearBatches[0]['langId']);
    }

    public function testAnArrayPhaseFallsThroughToIndexingRatherThanWarning(): void
    {
        $payload = $this->tick(['phase' => ['clear']]);

        $this->assertSame('index', $payload['phase']);
    }

    public function testAnArrayCursorIsNoCursor(): void
    {
        $this->tick(['phase' => 'index', 'lastId' => ['a-1']]);

        $this->assertSame('', $this->provideCalls[0]['lastId']);
    }

    public function testAnArrayBatchSizeFallsBackToTheDefault(): void
    {
        $this->tick(['phase' => 'index', 'batchSize' => ['5000']]);

        $this->assertSame(200, $this->provideCalls[0]['batchSize']);
    }

    /**
     * Everything in a query string is a string: `?done=500` hands the
     * controller "500", never 500. The arithmetic below only works because
     * the values are converted rather than assumed - and the module declares
     * strict_types, so an uncast string would not even survive the return.
     */
    public function testTheNumbersArriveAsStringsAndAreStillNumbers(): void
    {
        $this->batches = [$this->documents(3)];

        $payload = $this->tick([
            'phase' => 'index',
            'lastId' => 'a-1',
            'done' => '500',
            'total' => '999',
            'batchSize' => '750',
        ]);

        $this->assertSame(503, $payload['done']);
        $this->assertSame(999, $payload['total']);
        $this->assertSame(750, $payload['batchSize']);
    }

    public function testAnArrayRunningTotalCountsAsNone(): void
    {
        $payload = $this->tick(['phase' => 'index', 'done' => ['500']]);

        $this->assertSame(0, $payload['done']);
    }

    // ---------------------------------------------------------------
    // clearing the scope
    // ---------------------------------------------------------------

    /**
     * Clearing a large scope in one statement measured 21 seconds - past what
     * a web request may take, and the very thing the batching exists for.
     */
    public function testTheClearPhaseDeletesInBoundedBatches(): void
    {
        $this->writer->clearCounts = [5000];

        $payload = $this->tick(['phase' => 'clear', 'langId' => 1]);

        $this->assertSame(['shopId' => 1, 'langId' => 1, 'limit' => 5000], $this->writer->clearBatches[0]);
        $this->assertSame(5000, $payload['deleted']);
    }

    public function testTheClearPhaseIsFinishedOnlyWhenNothingIsLeftToDelete(): void
    {
        $this->writer->clearCounts = [5000, 0];

        $this->assertFalse($this->tick(['phase' => 'clear'])['finished']);
        $this->assertTrue($this->tick(['phase' => 'clear'])['finished']);
    }

    /**
     * A partial batch means rows are gone but more remain - saying "finished"
     * there would leave half the old scope in the index.
     */
    public function testAPartialClearBatchIsNotFinished(): void
    {
        $this->writer->clearCounts = [12];

        $this->assertFalse($this->tick(['phase' => 'clear'])['finished']);
    }

    // ---------------------------------------------------------------
    // indexing a batch
    // ---------------------------------------------------------------

    /**
     * The clear phase already emptied the scope, so every tick joins a run
     * rather than starting one - begin() here would delete it all again.
     */
    public function testAnIndexTickResumesTheRunRatherThanBeginningIt(): void
    {
        $this->tick(['phase' => 'index']);

        $this->assertContains('resume', $this->writer->steps);
        $this->assertNotContains('begin', $this->writer->steps);
    }

    public function testTheResumedScopeIsTheOneBeingRebuilt(): void
    {
        $this->host->editShopId = 2;

        $this->tick(['phase' => 'index', 'langId' => 1]);

        $this->assertSame([['shopId' => 2, 'langId' => 1]], $this->writer->resumedWith);
    }

    public function testTheDocumentsTheProviderReturnedAreWritten(): void
    {
        $this->batches = [$this->documents(3)];

        $this->tick(['phase' => 'index']);

        $this->assertSame([3], $this->writer->batchSizes());
    }

    public function testTheCursorIsCarriedIntoTheProvider(): void
    {
        $this->tick(['phase' => 'index', 'lastId' => 'a-42']);

        $this->assertSame('a-42', $this->provideCalls[0]['lastId']);
    }

    public function testTheNewCursorGoesBackToTheBrowser(): void
    {
        $this->batches = [$this->documents(2)];
        $this->nextLastId = 'a-99';

        $this->assertSame('a-99', $this->tick(['phase' => 'index'])['lastId']);
    }

    /**
     * The running total lives in the client, so each tick adds its own count
     * to what the browser carried in.
     */
    public function testTheRunningTotalAccumulatesAcrossTicks(): void
    {
        $this->batches = [$this->documents(3)];

        $payload = $this->tick(['phase' => 'index', 'done' => 500, 'lastId' => 'a-1']);

        $this->assertSame(503, $payload['done']);
    }

    public function testANegativeRunningTotalCannotDragTheCountBackwards(): void
    {
        $this->batches = [$this->documents(3)];

        $payload = $this->tick(['phase' => 'index', 'done' => -100, 'lastId' => 'a-1']);

        $this->assertSame(3, $payload['done']);
    }

    /**
     * A short batch is how the run ends: the provider had nothing more to give.
     */
    public function testAShortBatchFinishesTheRunAndCommitsIt(): void
    {
        $this->batches = [$this->documents(5)];

        $payload = $this->tick(['phase' => 'index', 'batchSize' => 200]);

        $this->assertTrue($payload['finished']);
        $this->assertContains('commit', $this->writer->steps);
    }

    public function testAFullBatchIsNotTheEndAndIsNotCommitted(): void
    {
        $this->batches = [$this->documents(50)];

        $payload = $this->tick(['phase' => 'index', 'batchSize' => 50]);

        $this->assertFalse($payload['finished']);
        $this->assertNotContains('commit', $this->writer->steps);
    }

    public function testAnEmptyScopeFinishesOnItsFirstTick(): void
    {
        $payload = $this->tick(['phase' => 'index']);

        $this->assertTrue($payload['finished']);
        $this->assertSame(0, $payload['done']);
    }

    /**
     * Counting the catalogue is a query of its own, and the answer cannot
     * change mid-run - so it is asked once, on the tick that has no cursor yet.
     */
    public function testTheTotalIsCountedOnceAtTheStartOfTheRun(): void
    {
        $this->articleCounts = ['1_0' => 1234];

        $this->assertSame(1234, $this->tick(['phase' => 'index'])['total']);
    }

    public function testALaterTickTakesTheTotalFromTheBrowserRatherThanCountingAgain(): void
    {
        $this->articleCounts = ['1_0' => 1234];

        $payload = $this->tick(['phase' => 'index', 'lastId' => 'a-1', 'total' => 999]);

        $this->assertSame(999, $payload['total']);
    }

    // ---------------------------------------------------------------
    // how much a tick may do
    // ---------------------------------------------------------------

    public function testABrowserThatAsksForNothingGetsTheDefaultBatchSize(): void
    {
        $this->tick(['phase' => 'index']);

        $this->assertSame(200, $this->provideCalls[0]['batchSize']);
    }

    public function testABatchSizeOfZeroIsTheDefaultRatherThanZero(): void
    {
        $this->tick(['phase' => 'index', 'batchSize' => 0]);

        $this->assertSame(200, $this->provideCalls[0]['batchSize']);
    }

    public function testANegativeBatchSizeIsTheDefaultToo(): void
    {
        $this->tick(['phase' => 'index', 'batchSize' => -50]);

        $this->assertSame(200, $this->provideCalls[0]['batchSize']);
    }

    /**
     * The number arrives from a form field, so it is clamped rather than
     * trusted: the ceiling is about the size of the INSERT a tick writes.
     */
    public function testAnOversizedRequestIsClampedToTheCeiling(): void
    {
        $this->tick(['phase' => 'index', 'batchSize' => 100000]);

        $this->assertSame(2000, $this->provideCalls[0]['batchSize']);
    }

    public function testAnUndersizedRequestIsRaisedToTheFloor(): void
    {
        $this->tick(['phase' => 'index', 'batchSize' => 1]);

        $this->assertSame(50, $this->provideCalls[0]['batchSize']);
    }

    public function testABatchSizeInsideTheBoundsIsHonoured(): void
    {
        $this->tick(['phase' => 'index', 'batchSize' => 750]);

        $this->assertSame(750, $this->provideCalls[0]['batchSize']);
    }

    public function testTheBoundsThemselvesAreHonoured(): void
    {
        $this->tick(['phase' => 'index', 'batchSize' => 50]);
        $this->tick(['phase' => 'index', 'batchSize' => 2000]);

        $this->assertSame(50, $this->provideCalls[0]['batchSize']);
        $this->assertSame(2000, $this->provideCalls[1]['batchSize']);
    }

    /**
     * Echoed back so the browser tunes against what actually ran rather than
     * against what it asked for - the whole point of the clamping above.
     */
    public function testTheBatchSizeThatRanIsEchoedBackNotTheOneAskedFor(): void
    {
        $this->assertSame(2000, $this->tick(['phase' => 'index', 'batchSize' => 100000])['batchSize']);
    }

    // ---------------------------------------------------------------
    // category assignments
    // ---------------------------------------------------------------

    public function testTheCategoryPhaseRebuildsTheScopeBeingEdited(): void
    {
        $this->host->editShopId = 2;

        $this->tick(['phase' => 'category', 'langId' => 1]);

        $this->assertSame(
            [['shopId' => 2, 'langId' => 1, 'force' => false]],
            $this->writer->categoryRebuilds
        );
    }

    public function testAPublishedRebuildReportsWhatItWrote(): void
    {
        $this->writer->categoryResults['1_0'] = RebuildResult::published('category assignments', 812, 800);

        $payload = $this->tick(['phase' => 'category']);

        $this->assertSame(812, $payload['categories']);
        $this->assertTrue($payload['published']);
        $this->assertTrue($payload['finished']);
        $this->assertSame('812 category assignments published (was 800)', $payload['message']);
    }

    /**
     * The writer can refuse - rebuilding while an ERP import has the source
     * table truncated would blank every category page. That refusal has to
     * reach the screen rather than be reported as a rebuild that happened.
     */
    public function testARefusedRebuildIsReportedAsSuchRatherThanAsSuccess(): void
    {
        $this->writer->categoryResults['1_0'] = RebuildResult::skipped('category assignments', 3, 800);

        $payload = $this->tick(['phase' => 'category']);

        $this->assertFalse($payload['published']);
        $this->assertSame(0, $payload['categories']);
        $this->assertStringContainsString('refused to publish', $payload['message']);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['finished']);
    }

    // ---------------------------------------------------------------
    // the suggest dictionary
    // ---------------------------------------------------------------

    public function testTheDictionaryPhaseBuildsTheScopeBeingEdited(): void
    {
        $this->host->editShopId = 4;
        $this->dictionaryTerms = 5120;

        $payload = $this->tick(['phase' => 'dictionary', 'langId' => 2]);

        $this->assertSame([['shopId' => 4, 'langId' => 2]], $this->dictionaryCalls);
        $this->assertSame(5120, $payload['terms']);
        $this->assertTrue($payload['finished']);
    }

    // ---------------------------------------------------------------
    // a tick that fails
    // ---------------------------------------------------------------

    /**
     * The client drives the loop, so a failure has to come back as JSON. An
     * exception that escapes leaves the browser waiting on a response that
     * never says what went wrong.
     */
    public function testAFailedTickAnswersTheBrowserInsteadOfThrowing(): void
    {
        $this->writer->failOnBatch = 0;
        $this->batches = [$this->documents(2)];

        $payload = $this->tick(['phase' => 'index']);

        $this->assertFalse($payload['ok']);
        $this->assertSame('the index refused the batch', $payload['message']);
    }

    public function testAFailedTickIsLoggedWithTheExceptionBehindIt(): void
    {
        $this->writer->failOnBatch = 0;
        $this->batches = [$this->documents(2)];

        $this->tick(['phase' => 'index']);

        $this->assertSame(
            ['foun10EasySearch: reindex tick failed - the index refused the batch'],
            $this->host->loggedErrors
        );
        $this->assertInstanceOf(RuntimeException::class, $this->host->loggedExceptions[0]);
    }

    /**
     * A failed tick reports nothing else: a payload carrying both ok=false and
     * a cursor would let the browser carry on from a batch that never landed.
     */
    public function testAFailedTickCarriesNoProgressForTheBrowserToContinueFrom(): void
    {
        $this->writer->failOnBatch = 0;
        $this->batches = [$this->documents(2)];

        $payload = $this->tick(['phase' => 'index']);

        $this->assertSame(['ok', 'message'], array_keys($payload));
    }

    public function testAFailureInAnyPhaseIsReportedTheSameWay(): void
    {
        $this->writer->categoryResults = [];
        $host = new TestableReindexPhases(['phase' => 'category']);
        $host->services = [];

        $host->reindexTick();

        $this->assertFalse($host->lastPayload()['ok']);
        $this->assertCount(1, $host->loggedErrors);
    }

    // ---------------------------------------------------------------
    // the scopes the browser walks
    // ---------------------------------------------------------------

    /**
     * Active only. Indexing a language the shop does not serve costs the same
     * minutes as one it does, for a catalogue nobody can search.
     */
    public function testTheBrowserIsGivenTheActiveLanguagesOfTheEditedShop(): void
    {
        $this->host->editShopId = 2;
        $this->activeLanguages = [1 => [0], 2 => [0, 1, 3]];

        $this->assertSame(
            [['langId' => 0], ['langId' => 1], ['langId' => 3]],
            $this->host->getReindexScopes()
        );
    }

    public function testAShopServingNoLanguageOffersNoScope(): void
    {
        $this->activeLanguages = [];

        $this->assertSame([], $this->host->getReindexScopes());
    }

    /**
     * The phase names are what the JavaScript sends back on every tick, so
     * they are a published contract rather than an implementation detail -
     * and they live in one class because both admin screens and the script
     * have to agree on them.
     */
    public function testThePhaseNamesAreThePublicContractWithTheBrowser(): void
    {
        $this->assertSame('clear', Reindex::PHASE_CLEAR);
        $this->assertSame('index', Reindex::PHASE_INDEX);
        $this->assertSame('category', Reindex::PHASE_CATEGORY);
        $this->assertSame('dictionary', Reindex::PHASE_DICTIONARY);
    }

    /**
     * The bounds are a documented trade-off - the ceiling is about the size of
     * the INSERT a tick writes, not about speed - so they are pinned by value.
     */
    public function testTheBatchBoundsAreTheMeasuredOnes(): void
    {
        $this->assertSame(200, Reindex::BATCH_SIZE);
        $this->assertSame(50, Reindex::BATCH_MIN);
        $this->assertSame(2000, Reindex::BATCH_MAX);
        $this->assertSame(5000, Reindex::CLEAR_BATCH_SIZE);
    }
}
