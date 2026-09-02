<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Command;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Index\DictionaryBuilder;
use foun10\EasySearch\Index\DocumentProvider;
use foun10\EasySearch\Index\IndexDocument;
use foun10\EasySearch\Index\IndexWriterLocator;
use foun10\EasySearch\Index\RebuildResult;
use foun10\EasySearch\Tests\Unit\Double\SpyIndexWriter;
use foun10\EasySearch\Tests\Unit\Double\TestableReindexCommand;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The reindex command.
 *
 * This is the entry point a cron job and a deployment use, so what it has to
 * get right is not the indexing - that belongs to the provider and the writer -
 * but the decisions around it: which scopes a run covers, which backend it
 * fills, in what order the derived data is refreshed afterwards, and above all
 * what happens when something fails halfway.
 *
 * The last one is the reason for the whole shadow-table design: a failed run
 * must roll back and leave the live index exactly as it was, and it must say
 * so with a non-zero exit code, or a cron will report success for weeks while
 * the index rots.
 */
class ReindexCommandTest extends TestCase
{
    private SpyIndexWriter $writer;

    private TestableReindexCommand $command;

    private CommandTester $tester;

    /** @var array<int, int[]> Active language IDs per shop */
    private array $activeLanguages = [1 => [0]];

    /** @var array<string, int> Article counts keyed "shopId_langId" */
    private array $articleCounts = [];

    /** @var array<string, int> Dictionary terms per scope */
    private array $dictionaryTerms = [];

    /** @var array<int, array{shopId: int, langId: int, batchSize: int}> */
    private array $provideCalls = [];

    /** @var array<int, array{shopId: int, langId: int}> */
    private array $dictionaryCalls = [];

    private string $configuredEngine = 'mysql';

    /** @var string[] */
    private array $knownEngines = ['mysql', 'meilisearch'];

    private ?string $requestedEngine = null;

    protected function setUp(): void
    {
        $this->writer = new SpyIndexWriter();

        $shopLanguages = $this->createMock(ShopLanguages::class);
        $shopLanguages->method('getActiveIds')->willReturnCallback(
            fn (?int $shopId = null): array => $this->activeLanguages[$shopId] ?? []
        );

        $documentProvider = $this->createMock(DocumentProvider::class);
        $documentProvider->method('countArticles')->willReturnCallback(
            fn (int $shopId, int $langId): int => $this->articleCounts[$shopId . '_' . $langId] ?? 0
        );
        $documentProvider->method('provide')->willReturnCallback(
            function (int $shopId, int $langId, int $batchSize = 500): Generator {
                $this->provideCalls[] = ['shopId' => $shopId, 'langId' => $langId, 'batchSize' => $batchSize];

                $count = $this->articleCounts[$shopId . '_' . $langId] ?? 0;

                for ($i = 0; $i < $count; $i++) {
                    yield $this->document('doc-' . $shopId . '-' . $langId . '-' . $i, $shopId, $langId);
                }
            }
        );

        $locator = $this->createMock(IndexWriterLocator::class);
        $locator->method('getConfigured')->willReturnCallback(function (): SpyIndexWriter {
            $this->requestedEngine = null;

            return $this->writer;
        });
        $locator->method('get')->willReturnCallback(function (string $name): SpyIndexWriter {
            if (!in_array($name, $this->knownEngines, true)) {
                throw new InvalidArgumentException('Unknown search engine "' . $name . '"');
            }

            $this->requestedEngine = $name;

            return $this->writer;
        });
        $locator->method('getNames')->willReturnCallback(fn (): array => $this->knownEngines);

        $dictionaryBuilder = $this->createMock(DictionaryBuilder::class);
        $dictionaryBuilder->method('build')->willReturnCallback(
            function (int $shopId, int $langId): int {
                $this->dictionaryCalls[] = ['shopId' => $shopId, 'langId' => $langId];

                return $this->dictionaryTerms[$shopId . '_' . $langId] ?? 0;
            }
        );

        $moduleSettings = $this->createMock(ModuleSettings::class);
        $moduleSettings->method('getEngine')->willReturnCallback(fn (): string => $this->configuredEngine);

        $this->command = new TestableReindexCommand(
            $shopLanguages,
            $documentProvider,
            $locator,
            $dictionaryBuilder,
            $moduleSettings
        );

        $this->tester = new CommandTester($this->command);
    }

    private function document(string $id, int $shopId, int $langId): IndexDocument
    {
        return new IndexDocument(
            $id,
            $shopId,
            $langId,
            'a-1',
            '',
            'a-1',
            'Titel',
            '',
            '',
            '',
            '',
            '',
            [],
            [],
            'search text',
            'boost text',
            9.99,
            1.0,
            0,
            null,
            true,
            []
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options = []): int
    {
        return $this->tester->execute($options);
    }

    private function display(): string
    {
        return $this->tester->getDisplay();
    }

    /**
     * The display with its whitespace collapsed.
     *
     * SymfonyStyle wraps error and warning blocks to the terminal width, so a
     * message can arrive split across two lines with padding in between - and
     * an assertion on the sentence has no business depending on that.
     */
    private function displayText(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->display()));
    }

    /**
     * @return array<int, array{shopId: int, langId: int}>
     */
    private function indexedScopes(): array
    {
        return array_map(
            static fn (array $call): array => ['shopId' => $call['shopId'], 'langId' => $call['langId']],
            $this->provideCalls
        );
    }

    // ---------------------------------------------------------------
    // which scopes a run covers
    // ---------------------------------------------------------------

    /**
     * Every shop, and of each only the languages it actually serves -
     * indexing a language nobody can reach spends minutes on a catalogue no
     * customer will ever search.
     */
    public function testWithoutOptionsEveryShopAndItsActiveLanguagesAreIndexed(): void
    {
        $this->command->allShopIds = [1, 2];
        $this->activeLanguages = [1 => [0, 1], 2 => [0]];
        $this->articleCounts = ['1_0' => 1, '1_1' => 1, '2_0' => 1];

        $this->assertSame(Command::SUCCESS, $this->runCommand());
        $this->assertSame(
            [
                ['shopId' => 1, 'langId' => 0],
                ['shopId' => 1, 'langId' => 1],
                ['shopId' => 2, 'langId' => 0],
            ],
            $this->indexedScopes()
        );
    }

    public function testAShopCanBeSingledOut(): void
    {
        $this->command->allShopIds = [1, 2];
        $this->activeLanguages = [1 => [0], 2 => [0, 1]];
        $this->articleCounts = ['2_0' => 1, '2_1' => 1];

        $this->runCommand(['--shop-id' => '2']);

        $this->assertSame(
            [['shopId' => 2, 'langId' => 0], ['shopId' => 2, 'langId' => 1]],
            $this->indexedScopes()
        );
    }

    /**
     * An explicit language is honoured even when the shop does not serve it
     * yet, so a translation can be indexed before it goes live.
     */
    public function testAnExplicitLanguageIsIndexedEvenWhenItIsNotActive(): void
    {
        $this->activeLanguages = [1 => [0]];
        $this->articleCounts = ['1_3' => 1];

        $this->runCommand(['--lang-id' => '3']);

        $this->assertSame([['shopId' => 1, 'langId' => 3]], $this->indexedScopes());
    }

    public function testAShopWithoutActiveLanguagesIsAnError(): void
    {
        $this->activeLanguages = [1 => []];

        $this->assertSame(Command::FAILURE, $this->runCommand());
        $this->assertStringContainsString('No shop/language combination to index', $this->displayText());
        $this->assertSame([], $this->writer->steps);
    }

    // ---------------------------------------------------------------
    // full rebuild versus scoped run
    // ---------------------------------------------------------------

    /**
     * An empty scope list is what tells the writer it may replace everything -
     * the swap that keeps the live index intact until the last moment.
     */
    public function testAFullRunLetsTheWriterReplaceEverything(): void
    {
        $this->articleCounts = ['1_0' => 1];

        $this->runCommand();

        $this->assertSame([], $this->writer->beganWith);
        $this->assertStringContainsString('full rebuild with swap', $this->display());
    }

    /**
     * A narrowed run must not swap, or the scopes it did not rebuild would be
     * replaced by nothing - so it hands the writer exactly what it covers.
     */
    public function testANarrowedRunNamesItsScopes(): void
    {
        $this->activeLanguages = [2 => [0, 1]];
        $this->articleCounts = ['2_0' => 1, '2_1' => 1];

        $this->runCommand(['--shop-id' => '2']);

        $this->assertSame(
            [['shopId' => 2, 'langId' => 0], ['shopId' => 2, 'langId' => 1]],
            $this->writer->beganWith
        );
        $this->assertStringContainsString('scoped in-place replace', $this->display());
    }

    public function testNamingOnlyALanguageIsAlsoAScopedRun(): void
    {
        $this->articleCounts = ['1_0' => 1];

        $this->runCommand(['--lang-id' => '0']);

        $this->assertSame([['shopId' => 1, 'langId' => 0]], $this->writer->beganWith);
    }

    // ---------------------------------------------------------------
    // which backend is filled
    // ---------------------------------------------------------------

    public function testWithoutAnEngineTheShopsSettingIsUsed(): void
    {
        $this->configuredEngine = 'meilisearch';
        $this->command->currentShopId = 3;
        $this->articleCounts = ['1_0' => 1];

        $this->runCommand();

        $this->assertNull($this->requestedEngine, 'the locator was asked for the configured one');
        $this->assertStringContainsString('meilisearch (setting of shop 3', $this->display());
    }

    /**
     * Naming the engine is what keeps both indexes current while a migration
     * is being evaluated.
     */
    public function testAnEngineCanBeNamed(): void
    {
        $this->articleCounts = ['1_0' => 1];

        $this->runCommand(['--engine' => 'meilisearch']);

        $this->assertSame('meilisearch', $this->requestedEngine);
        $this->assertStringContainsString('engine: meilisearch', $this->display());
    }

    /**
     * A typo must not silently fill the wrong backend, and the message has to
     * say what would have worked.
     */
    public function testAnUnknownEngineStopsTheRun(): void
    {
        $this->articleCounts = ['1_0' => 1];

        $this->assertSame(Command::FAILURE, $this->runCommand(['--engine' => 'elastic']));
        $this->assertStringContainsString('elastic', $this->displayText(), 'which engine was refused');
        $this->assertStringContainsString('known engines: mysql, meilisearch', $this->displayText());
        $this->assertSame([], $this->writer->steps, 'nothing was written');
    }

    // ---------------------------------------------------------------
    // the run itself
    // ---------------------------------------------------------------

    public function testAScopeIsWrittenInBatchesAndCommittedOnce(): void
    {
        $this->articleCounts = ['1_0' => 5];

        $this->assertSame(Command::SUCCESS, $this->runCommand(['--batch-size' => '2']));
        $this->assertSame([2, 2, 1], $this->writer->batchSizes(), 'the last batch is the remainder');
        $this->assertSame(
            ['begin', 'write', 'write', 'write', 'commit', 'categories'],
            $this->writer->steps
        );
    }

    public function testTheBatchSizeReachesTheProvider(): void
    {
        $this->articleCounts = ['1_0' => 3];

        $this->runCommand(['--batch-size' => '2']);

        $this->assertSame(2, $this->provideCalls[0]['batchSize']);
    }

    /**
     * A batch of zero would be an endless loop of empty writes.
     */
    public function testABatchSizeBelowOneBecomesOne(): void
    {
        $this->articleCounts = ['1_0' => 2];

        $this->runCommand(['--batch-size' => '0']);

        $this->assertSame(1, $this->provideCalls[0]['batchSize']);
        $this->assertSame([1, 1], $this->writer->batchSizes());
    }

    /**
     * A scope can legitimately hold nothing - a subshop just added, a language
     * whose catalogue has not been filled yet. It used to abort the run:
     * Symfony's progress bar refuses to project a remaining time without a
     * known total, the exception came out inside the try, and every scope
     * indexed before it was rolled back.
     */
    public function testAnEmptyScopeDoesNotAbortTheRun(): void
    {
        $this->articleCounts = ['1_0' => 0];

        $this->assertSame(Command::SUCCESS, $this->runCommand(), $this->display());
        $this->assertSame([], $this->writer->batches);
        $this->assertContains('commit', $this->writer->steps);
        $this->assertNotContains('rollback', $this->writer->steps);
    }

    public function testAnEmptyScopeDoesNotStopTheScopesAfterIt(): void
    {
        $this->activeLanguages = [1 => [0, 1]];
        $this->articleCounts = ['1_0' => 0, '1_1' => 3];

        $this->assertSame(Command::SUCCESS, $this->runCommand(), $this->display());
        $this->assertSame(3, $this->writer->documentsWritten());
    }

    public function testTheNumberOfIndexedDocumentsIsReported(): void
    {
        $this->articleCounts = ['1_0' => 3, '1_1' => 4];
        $this->activeLanguages = [1 => [0, 1]];

        $this->runCommand();

        $this->assertSame(7, $this->writer->documentsWritten());
        $this->assertStringContainsString('7 documents indexed', $this->display());
    }

    // ---------------------------------------------------------------
    // what the operator is told
    // ---------------------------------------------------------------

    /**
     * The header is what an operator reads before deciding to let a run
     * continue: how much work it is, against which backend, and whether the
     * live index is protected by a swap.
     */
    public function testTheRunAnnouncesItsScopeSizeAndMode(): void
    {
        $this->activeLanguages = [1 => [0, 1]];
        $this->articleCounts = ['1_0' => 1200, '1_1' => 800];

        $this->runCommand(['--batch-size' => '500']);

        $display = $this->display();

        $this->assertStringContainsString('Rebuilding search index', $display);
        $this->assertStringContainsString('2 scope(s), batch size 500', $display);
        $this->assertStringContainsString('2,000 documents to index in total', $display);
        $this->assertStringContainsString('Shop 1, language 0 (1,200 articles)', $display);
        $this->assertStringContainsString('Shop 1, language 1 (800 articles)', $display);
    }

    public function testTheProgressBarRunsToTheEndOfTheScope(): void
    {
        $this->articleCounts = ['1_0' => 5];

        $this->runCommand(['--batch-size' => '2']);

        $this->assertStringContainsString('5/5 [', $this->display(), 'the bar was advanced and finished');
        $this->assertStringContainsString('elapsed, ~', $this->display(), 'in the format this command sets');
    }

    public function testTheEndOfTheRunIsReportedWithAPlausibleDuration(): void
    {
        $this->articleCounts = ['1_0' => 5];

        $this->runCommand(['--batch-size' => '2']);

        $this->assertMatchesRegularExpression(
            '/5 documents indexed in (< 1 sec|[\d,.]+ (ms|secs?|mins?|hrs?))/',
            $this->display(),
            'the elapsed time, not a timestamp - which would read as decades'
        );
    }

    /**
     * The count reported at the end is what was actually handed to the writer,
     * summed over every batch and every scope - not the size of the last one.
     */
    public function testTheReportedCountIsTheSumOfEveryBatch(): void
    {
        $this->articleCounts = ['1_0' => 5];

        $this->runCommand(['--batch-size' => '2']);

        $this->assertSame(5, $this->writer->documentsWritten());
        $this->assertStringContainsString('5 documents indexed', $this->display());
    }

    /**
     * A per-scope bar says nothing about when eight scopes will be done, which
     * is why the run projects across all of them - but only while there is
     * something left to project.
     */
    public function testTheOverallProgressIsReportedBetweenScopes(): void
    {
        $this->activeLanguages = [1 => [0, 1]];
        $this->articleCounts = ['1_0' => 3, '1_1' => 7];

        $this->runCommand();

        $display = $this->display();

        $this->assertSame(1, substr_count($display, 'Overall:'), 'once, after the first of two scopes');
        $this->assertStringContainsString('Overall: 3/10 (30%)', $display);
    }

    /**
     * @dataProvider percentageProvider
     */
    public function testAPartialPercentageIsRounded(int $first, int $second, string $expected): void
    {
        $this->activeLanguages = [1 => [0, 1]];
        $this->articleCounts = ['1_0' => $first, '1_1' => $second];

        $this->runCommand();

        $this->assertStringContainsString($expected, $this->display());
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public function percentageProvider(): array
    {
        return [
            // 3 of 8 is 37.5, which rounds up rather than being truncated.
            'up' => [3, 5, 'Overall: 3/8 (38%)'],
            // 3 of 9 is 33.3, which does not round up.
            'down' => [3, 6, 'Overall: 3/9 (33%)'],
        ];
    }

    public function testTheLastScopeIsNotFollowedByAProjection(): void
    {
        $this->articleCounts = ['1_0' => 3];

        $this->runCommand();

        $this->assertStringNotContainsString('Overall:', $this->display(), 'there is nothing left to project');
    }

    public function testTheDerivedStepsAnnounceThemselves(): void
    {
        $this->articleCounts = ['1_0' => 1];

        $this->runCommand();

        $this->assertStringContainsString('Category assignments', $this->display());
        $this->assertStringContainsString(
            'Shop 1, language 0: 10 category assignments published (was 8)',
            $this->display()
        );
        $this->assertStringContainsString('Building correction dictionary', $this->display());
        $this->assertStringContainsString('Shop 1, language 0: 0 terms', $this->display());
    }

    // ---------------------------------------------------------------
    // failure
    // ---------------------------------------------------------------

    /**
     * The whole point of the shadow tables: a run that dies leaves the live
     * index exactly as it was.
     */
    public function testAFailedRunRollsBackAndSaysSo(): void
    {
        $this->articleCounts = ['1_0' => 4];
        $this->writer->failOnBatch = 1;

        $this->assertSame(Command::FAILURE, $this->runCommand(['--batch-size' => '2']));
        $this->assertSame(['begin', 'write', 'rollback'], $this->writer->steps);
        $this->assertStringContainsString('the live index was left untouched', $this->displayText());
        $this->assertStringContainsString(
            'the index refused the batch',
            $this->displayText(),
            'and what actually went wrong'
        );
    }

    public function testAFailedRunRefreshesNothingAfterwards(): void
    {
        $this->articleCounts = ['1_0' => 2];
        $this->writer->failOnBatch = 0;

        $this->runCommand(['--batch-size' => '2']);

        $this->assertNotContains('categories', $this->writer->steps);
        $this->assertSame([], $this->dictionaryCalls);
    }

    // ---------------------------------------------------------------
    // the derived data afterwards
    // ---------------------------------------------------------------

    /**
     * After the swap, because the assignments are derived from whatever group
     * IDs are live - reading them before the rename would describe the index
     * that just got replaced.
     */
    public function testTheCategoriesAreRefreshedAfterTheCommit(): void
    {
        $this->articleCounts = ['1_0' => 1];

        $this->runCommand();

        $steps = $this->writer->steps;

        $this->assertLessThan(
            array_search('categories', $steps, true),
            array_search('commit', $steps, true)
        );
    }

    public function testTheDictionaryIsBuiltForEveryScope(): void
    {
        $this->activeLanguages = [1 => [0, 1]];
        $this->articleCounts = ['1_0' => 1, '1_1' => 1];
        $this->dictionaryTerms = ['1_0' => 1200, '1_1' => 900];

        $this->runCommand();

        $this->assertSame(
            [['shopId' => 1, 'langId' => 0], ['shopId' => 1, 'langId' => 1]],
            $this->dictionaryCalls
        );
        $this->assertStringContainsString('1200 terms', $this->display());
    }

    public function testBothRefreshesCanBeSkipped(): void
    {
        $this->articleCounts = ['1_0' => 1];

        $this->runCommand(['--skip-categories' => true, '--skip-dictionary' => true]);

        $this->assertNotContains('categories', $this->writer->steps);
        $this->assertSame([], $this->dictionaryCalls);
    }

    // ---------------------------------------------------------------
    // the partial modes
    // ---------------------------------------------------------------

    public function testTheDictionaryCanBeRebuiltOnItsOwn(): void
    {
        $this->articleCounts = ['1_0' => 5];

        $this->assertSame(Command::SUCCESS, $this->runCommand(['--dictionary-only' => true]));
        $this->assertSame([], $this->writer->steps, 'the index is not touched');
        $this->assertSame([['shopId' => 1, 'langId' => 0]], $this->dictionaryCalls);
    }

    /**
     * The cron entry point: it runs against the live index and touches nothing
     * else, so it is safe to fire while customers are searching.
     */
    public function testTheCategoriesCanBeRebuiltOnTheirOwn(): void
    {
        $this->activeLanguages = [1 => [0, 1]];

        $this->assertSame(Command::SUCCESS, $this->runCommand(['--categories-only' => true]));
        $this->assertSame(['categories', 'categories'], $this->writer->steps);
        $this->assertSame([], $this->dictionaryCalls);
    }

    /**
     * A refusal is not a crash - the old assignments are still serving - but a
     * cron that has been declining to publish for a week must not look like
     * one that is working.
     */
    public function testARefusedCategoryRebuildFailsTheCommand(): void
    {
        $this->writer->categoryResults['1_0'] = RebuildResult::skipped('category assignments', 3, 500);

        $this->assertSame(Command::FAILURE, $this->runCommand(['--categories-only' => true]));
        $this->assertStringContainsString('refused to publish', $this->displayText());
    }

    public function testOneRefusedScopeIsEnoughToFail(): void
    {
        $this->activeLanguages = [1 => [0, 1]];
        $this->writer->categoryResults['1_1'] = RebuildResult::skipped('category assignments', 3, 500);

        $this->assertSame(Command::FAILURE, $this->runCommand(['--categories-only' => true]));
    }

    /**
     * The guard is there to be overridden by a human who knows the catalogue
     * really did shrink.
     */
    public function testTheGuardCanBeForced(): void
    {
        $this->runCommand(['--categories-only' => true, '--force-categories' => true]);

        $this->assertSame(
            [['shopId' => 1, 'langId' => 0, 'force' => true]],
            $this->writer->categoryRebuilds
        );
    }

    public function testWithoutForcingTheGuardStaysOn(): void
    {
        $this->runCommand(['--categories-only' => true]);

        $this->assertFalse($this->writer->categoryRebuilds[0]['force']);
    }

    /**
     * A full run that refuses to publish its categories still indexed
     * everything, so it reports success - the warning is in the output.
     */
    public function testARefusedCategoryRebuildDoesNotFailAFullRun(): void
    {
        $this->articleCounts = ['1_0' => 1];
        $this->writer->categoryResults['1_0'] = RebuildResult::skipped('category assignments', 3, 500);

        $this->assertSame(Command::SUCCESS, $this->runCommand());
        $this->assertStringContainsString('refused to publish', $this->displayText());
    }
}
