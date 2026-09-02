<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Command;

use foun10\EasySearch\Engine\EngineLocator;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Engine\Result\FacetValue;
use foun10\EasySearch\Log\Period;
use foun10\EasySearch\Log\SearchLog;
use foun10\EasySearch\Tests\Unit\Double\SpySearchEngine;
use foun10\EasySearch\Tests\Unit\Double\TestableBenchmarkCommand;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The connector benchmark.
 *
 * It exists for one decision - whether MySQL stays or Meilisearch takes over -
 * and the thing it must not do is make that decision on a bad measurement. So
 * what is pinned here is the method rather than the numbers: every engine
 * answers the identical query, each is warmed up before it is timed, the
 * measured runs are repeated, and the report says how much the engines agree,
 * because an engine that is faster because it finds less is not faster.
 *
 * The rest is refusals. Benchmarking against an index that does not exist, or
 * against terms nobody ever searched for, produces numbers that look like
 * evidence and are not.
 */
class BenchmarkCommandTest extends TestCase
{
    private TestableBenchmarkCommand $command;

    private CommandTester $tester;

    private SpySearchEngine $mysql;

    private SpySearchEngine $meili;

    /** @var string[] */
    private array $loggedTerms = ['jacke'];

    /** @var array<int, array{shopId: int, langId: int, limit: int, period: Period}> */
    private array $logCalls = [];

    protected function setUp(): void
    {
        $this->mysql = new SpySearchEngine(['p-1', 'p-2'], 120);
        $this->meili = new SpySearchEngine(['p-1', 'p-3'], 118);

        $locator = $this->createMock(EngineLocator::class);
        $locator->method('get')->willReturnCallback(function (string $name): SpySearchEngine {
            return match ($name) {
                'mysql' => $this->mysql,
                'meilisearch' => $this->meili,
                default => throw new InvalidArgumentException('Unknown search engine "' . $name . '"'),
            };
        });

        $searchLog = $this->createMock(SearchLog::class);
        $searchLog->method('getBenchmarkTerms')->willReturnCallback(
            function (int $shopId, int $langId, Period $period, int $limit): array {
                $this->logCalls[] = [
                    'shopId' => $shopId,
                    'langId' => $langId,
                    'limit' => $limit,
                    'period' => $period,
                ];

                return $this->loggedTerms;
            }
        );

        $this->command = new TestableBenchmarkCommand($locator, $searchLog);
        $this->tester = new CommandTester($this->command);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options = []): int
    {
        return $this->tester->execute($options + ['--runs' => '1', '--no-filter-scenario' => true]);
    }

    private function display(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->tester->getDisplay()));
    }

    private function facet(string $attributeId, string $valueId, string $label, int $count): Facet
    {
        return new Facet($attributeId, 'Farbe', [new FacetValue($valueId, $label, $count)]);
    }

    // ---------------------------------------------------------------
    // what it refuses to measure
    // ---------------------------------------------------------------

    public function testAnUnknownEngineStopsTheRun(): void
    {
        $this->assertSame(Command::FAILURE, $this->runCommand(['--engines' => 'elastic']));
        $this->assertStringContainsString('Unknown search engine "elastic"', $this->display());
        $this->assertSame(0, $this->mysql->searchCount());
    }

    public function testNamingNoEngineAtAllStopsTheRun(): void
    {
        $this->assertSame(Command::FAILURE, $this->runCommand(['--engines' => ' , ']));
        $this->assertStringContainsString('Name at least one engine', $this->display());
    }

    /**
     * One engine is a legitimate run - the comparison is then between two
     * machines rather than two connectors, brought together with --compare.
     */
    public function testASingleEngineIsAllowed(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand(['--engines' => 'mysql']));
        $this->assertSame(0, $this->meili->searchCount());
    }

    /**
     * Measuring against a missing index would time the fallback, not the
     * engine - and it would look like a result.
     */
    public function testAnEngineWithoutAnIndexStopsTheRun(): void
    {
        $this->meili->available = false;

        $this->assertSame(Command::FAILURE, $this->runCommand());

        $display = $this->display();

        $this->assertStringContainsString('no index - run foun10:easysearch:reindex --engine=meilisearch', $display);
        $this->assertStringContainsString('Every engine named has to be indexed first', $display);
        $this->assertSame(0, $this->mysql->searchCount(), 'nothing was measured');
    }

    /**
     * Shop 1 and language 0 are the defaults, and the header is where an
     * operator checks that the run measured what they meant.
     */
    public function testTheHeaderNamesTheScopeAndTheMethod(): void
    {
        $this->runCommand(['--terms' => 'jacke']);

        $display = $this->display();

        $this->assertStringContainsString('Search connector benchmark', $display);
        $this->assertStringContainsString('Shop 1, language 0, 1 runs per scenario, 24 hits per page', $display);
    }

    public function testAPageOfZeroHitsIsStillAPageOfOne(): void
    {
        $this->runCommand(['--terms' => 'jacke', '--limit' => '0']);

        $this->assertStringContainsString('1 hits per page', $this->display());
        $this->assertSame(1, $this->mysql->searches[0]->getLimit());
    }

    public function testTheAvailabilityTableNamesEveryEngine(): void
    {
        $this->runCommand();

        $display = $this->display();

        $this->assertStringContainsString('engine state', $display);
        $this->assertStringContainsString('mysql ready', $display);
        $this->assertStringContainsString('meilisearch ready', $display);
    }

    // ---------------------------------------------------------------
    // which terms are measured
    // ---------------------------------------------------------------

    /**
     * What customers actually type beats anything invented here, so the log is
     * the default source - not a hand written list, which would only measure
     * how the engines handle that list.
     */
    public function testByDefaultTheTermsCustomersTypedAreUsed(): void
    {
        $this->loggedTerms = ['jacke', 'bikini'];

        $this->runCommand(['--shop-id' => '2', '--lang-id' => '1']);

        $this->assertSame(2, $this->logCalls[0]['shopId']);
        $this->assertSame(1, $this->logCalls[0]['langId']);
        $this->assertSame(20, $this->logCalls[0]['limit'], 'the default sample size');
        $this->assertStringContainsString('search "jacke"', $this->display());
        $this->assertStringContainsString('search "bikini"', $this->display());
    }

    public function testTheNumberOfLoggedTermsCanBeChosen(): void
    {
        $this->runCommand(['--terms-from-log' => '5']);

        $this->assertSame(5, $this->logCalls[0]['limit']);
    }

    public function testAtLeastOneTermIsAskedForEvenWhenZeroIsRequested(): void
    {
        $this->runCommand(['--terms-from-log' => '0']);

        $this->assertSame(1, $this->logCalls[0]['limit']);
    }

    public function testExplicitTermsWinAndAreTrimmed(): void
    {
        $this->runCommand(['--terms' => ' jacke , , bikini ']);

        $display = $this->display();

        $this->assertStringContainsString('search "jacke"', $display);
        $this->assertStringContainsString('search "bikini"', $display);
        $this->assertStringNotContainsString('search ""', $display, 'a stray comma is not a term');
        $this->assertSame([], $this->logCalls, 'the log was not consulted');
    }

    /**
     * A fresh shop, or logging switched off: the catalogue's own vocabulary is
     * the next best load profile, because those are the words the products are
     * described with.
     */
    public function testWithoutLoggedTermsTheDictionaryIsSampled(): void
    {
        $this->loggedTerms = [];
        $this->command->dictionaryRows = [['FOUN10TERMRAW' => 'spitze'], ['FOUN10TERMRAW' => 'buegel']];

        $this->runCommand();

        $this->assertStringContainsString('search "spitze"', $this->display());
        $this->assertStringContainsString('search "buegel"', $this->display(), 'every sampled term, not the first');
        $this->assertStringContainsString('FROM foun10easysearchdictionary', $this->command->queries[0]);
        $this->assertStringContainsString('LIMIT 20', $this->command->queries[0]);
        $this->assertSame(
            [':shopId' => 1, ':langId' => 0],
            $this->command->queryParameters[0],
            'the sample belongs to one scope'
        );
    }

    public function testTheDictionarySampleSizeCanBeChosen(): void
    {
        $this->loggedTerms = [];
        $this->command->dictionaryRows = [['FOUN10TERMRAW' => 'spitze']];

        $this->runCommand(['--terms-from-dictionary' => '7']);

        $this->assertStringContainsString('LIMIT 7', $this->command->queries[0]);
    }

    /**
     * A dictionary table that is not there yet is not an error - it only means
     * this source has nothing to offer.
     */
    public function testAnUnreadableDictionaryIsNotFatal(): void
    {
        $this->loggedTerms = [];
        $this->command->dictionaryFailure = 'Table foun10easysearchdictionary does not exist';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pass --terms=one,two,three');

        $this->runCommand();
    }

    /**
     * Nothing logged and nothing indexed: the command says which two ways out
     * there are rather than measuring nothing.
     */
    public function testWithoutAnyTermsItSaysWhatToDo(): void
    {
        $this->loggedTerms = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'No search terms available: the log is empty for the last 90 days and the dictionary '
            . 'has not been built yet. Run a reindex, or pass --terms=one,two,three.'
        );

        $this->runCommand();
    }

    // ---------------------------------------------------------------
    // the scenarios
    // ---------------------------------------------------------------

    public function testACategoryListingCanBeMeasuredAsWell(): void
    {
        $this->runCommand(['--category' => 'c-1']);

        $this->assertStringContainsString('category listing', $this->display());

        $categoryQueries = array_values(array_filter(
            $this->mysql->searches,
            static fn ($query): bool => $query->getCategoryId() === 'c-1'
        ));

        $this->assertNotSame([], $categoryQueries);
        $this->assertSame('', $categoryQueries[0]->getTerm(), 'a listing is not a search');
    }

    /**
     * The expensive shape for both connectors: a selected facet has to be
     * counted with its own selection removed, so it can no longer share the
     * query that answers all the others.
     */
    public function testAFacetIsPickedFromARealResultForTheFilterScenario(): void
    {
        $this->mysql->facets = [$this->facet('at-color', 'v-red', 'Rot', 12)];

        $this->tester->execute(['--runs' => '1', '--terms' => 'jacke']);

        $this->assertStringContainsString('search "jacke" + filter Rot', $this->display());

        $filtered = array_values(array_filter(
            $this->mysql->searches,
            static fn ($query): bool => $query->getFilters() !== []
        ));

        $this->assertNotSame([], $filtered);
        $this->assertSame('at-color', $filtered[0]->getFilters()[0]->getAttributeId());
        $this->assertSame(['v-red'], $filtered[0]->getFilters()[0]->getValueIds());
        $this->assertSame('jacke', $filtered[0]->getTerm(), 'the same term as the scenario it extends');
    }

    public function testAValueNothingMatchesIsNotWorthFilteringOn(): void
    {
        $this->mysql->facets = [$this->facet('at-color', 'v-red', 'Rot', 0)];

        $this->tester->execute(['--runs' => '1', '--terms' => 'jacke']);

        $this->assertStringNotContainsString('+ filter', $this->display());
    }

    /**
     * One product is enough to filter on - the scenario is about the shape of
     * the query, not about how much it returns.
     */
    public function testAValueWithASingleProductIsWorthFilteringOn(): void
    {
        $this->mysql->facets = [$this->facet('at-color', 'v-red', 'Rot', 1)];

        $this->tester->execute(['--runs' => '1', '--terms' => 'jacke']);

        $this->assertStringContainsString('+ filter Rot', $this->display());
    }

    /**
     * An empty value is skipped, not the end of the search for one.
     */
    public function testTheSearchForAUsableValueContinuesPastAnEmptyOne(): void
    {
        $this->mysql->facets = [
            new Facet('at-color', 'Farbe', [
                new FacetValue('v-gone', 'Vergriffen', 0),
                new FacetValue('v-red', 'Rot', 5),
            ]),
        ];

        $this->tester->execute(['--runs' => '1', '--terms' => 'jacke']);

        $this->assertStringContainsString('+ filter Rot', $this->display());
    }

    public function testWithoutFacetsThereIsNoFilterScenario(): void
    {
        $this->tester->execute(['--runs' => '1', '--terms' => 'jacke']);

        $this->assertStringNotContainsString('+ filter', $this->display());
    }

    public function testTheFilterScenarioCanBeSkipped(): void
    {
        $this->mysql->facets = [$this->facet('at-color', 'v-red', 'Rot', 12)];

        $this->runCommand(['--terms' => 'jacke']);

        $this->assertStringNotContainsString('+ filter', $this->display());
    }

    /**
     * The probe that looks for a facet value runs against a live engine. If it
     * fails, the benchmark continues without that scenario.
     */
    public function testAFailedProbeCostsOnlyTheFilterScenario(): void
    {
        $this->mysql->failing = true;

        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--runs' => '1', '--terms' => 'jacke']));
        $this->assertStringNotContainsString('+ filter', $this->display());
    }

    // ---------------------------------------------------------------
    // how it measures
    // ---------------------------------------------------------------

    /**
     * Both engines cache - MySQL in its buffer pool, Meilisearch in the page
     * cache - so the first call would measure the disk. It is run and thrown
     * away.
     */
    public function testEveryEngineIsWarmedUpBeforeItIsTimed(): void
    {
        $this->runCommand(['--terms' => 'jacke', '--runs' => '3']);

        $this->assertSame(4, $this->mysql->searchCount(), 'one warm-up plus three measured');
        $this->assertSame(4, $this->meili->searchCount());
    }

    public function testEveryEngineAnswersTheIdenticalQuery(): void
    {
        $this->runCommand(['--terms' => 'jacke', '--limit' => '48', '--shop-id' => '2', '--lang-id' => '1']);

        $left = $this->mysql->searches[0];
        $right = $this->meili->searches[0];

        $this->assertSame($left->getTerm(), $right->getTerm());
        $this->assertSame($left->getShopId(), $right->getShopId());
        $this->assertSame($left->getLangId(), $right->getLangId());
        $this->assertSame(48, $left->getLimit());
        $this->assertSame(48, $right->getLimit());
        $this->assertSame(0, $left->getOffset(), 'the first page, which is what a listing is judged on');
        $this->assertSame(0, $right->getOffset());
    }

    public function testAtLeastOneRunIsMeasured(): void
    {
        $this->tester->execute(['--terms' => 'jacke', '--runs' => '0', '--no-filter-scenario' => true]);

        $this->assertSame(2, $this->mysql->searchCount(), 'the warm-up plus one');
    }

    public function testTheHitCountsOfBothEnginesAreReported(): void
    {
        $this->runCommand(['--terms' => 'jacke']);

        $display = $this->display();

        $this->assertStringContainsString('engine hits facets median p95 min max', $display);
        $this->assertStringContainsString('mysql 120', $display);
        $this->assertStringContainsString('meilisearch 118', $display);
    }

    /**
     * An engine that is faster because it finds less is not faster, so the
     * report says how much of the first page the two engines share.
     */
    public function testTheAgreementOfTheFirstPageIsReported(): void
    {
        $this->mysql->productIds = ['p-1', 'p-2', 'p-3'];
        $this->meili->productIds = ['p-1', 'p-3', 'p-9'];

        $this->runCommand(['--terms' => 'jacke']);

        $this->assertStringContainsString(
            'First page: 2 of 3 products shared, 1 in the same position',
            $this->display()
        );
    }

    public function testWithOneEngineThereIsNothingToAgreeWith(): void
    {
        $this->runCommand(['--engines' => 'mysql', '--terms' => 'jacke']);

        $this->assertStringNotContainsString('First page:', $this->display());
    }

    /**
     * A broken engine must not abort a measurement halfway - it reports zero
     * hits, which is exactly what it delivered.
     */
    public function testAnEngineThatFailsIsMeasuredAsFindingNothing(): void
    {
        $this->meili->failing = true;

        $this->assertSame(Command::SUCCESS, $this->runCommand(['--terms' => 'jacke']));
        $this->assertStringContainsString('meilisearch 0', $this->display());
    }

    // ---------------------------------------------------------------
    // the summary
    // ---------------------------------------------------------------

    public function testTheSummaryComparesEveryEngineAgainstTheFirstOne(): void
    {
        $this->runCommand(['--terms' => 'jacke,bikini']);

        $display = $this->display();

        $this->assertStringContainsString('Summary', $display);
        $this->assertStringContainsString('engine sum of medians per scenario speed vs mysql', $display);
        $this->assertStringContainsString('A factor above 1.00x means faster than mysql.', $display);
    }

    /**
     * The baseline is exactly as fast as itself, which is the one factor in
     * the table that can be checked rather than measured. Both engines are
     * given something to spend time on, because a run too fast for the clock
     * reports no factor at all - deliberately, rather than dividing by zero.
     */
    public function testTheBaselineIsOneAgainstItself(): void
    {
        $this->mysql->delayMicroseconds = 2000;
        $this->meili->delayMicroseconds = 2000;

        $this->runCommand(['--terms' => 'jacke']);

        $display = $this->display();

        $this->assertMatchesRegularExpression(
            '/mysql [\\d.,]+ ms [\\d.,]+ ms 1\\.00x/',
            $display,
            'and the row carries all four columns'
        );
        $this->assertMatchesRegularExpression(
            '/meilisearch [\\d.,]+ ms [\\d.,]+ ms [\\d.,]+x/',
            $display,
            'the other engine gets a factor against it'
        );
    }

    // ---------------------------------------------------------------
    // writing and reading a run
    // ---------------------------------------------------------------

    /**
     * Bare numbers are worthless the moment there are two files: which
     * machine, which shop, which terms.
     */
    public function testTheRawMeasurementsAreWrittenWithTheirContext(): void
    {
        $this->runCommand(['--terms' => 'jacke', '--shop-id' => '2', '--lang-id' => '1', '--json' => 'run.json']);

        $this->assertArrayHasKey('run.json', $this->command->files);

        $payload = json_decode($this->command->files['run.json'], true);

        $this->assertSame(2, $payload['shopId']);
        $this->assertSame(1, $payload['langId']);
        $this->assertSame(1, $payload['runs']);
        $this->assertSame(['mysql', 'meilisearch'], $payload['engines']);
        $this->assertSame('search "jacke"', $payload['measurements'][0]['scenario']);
        $this->assertLessThan(
            1000.0,
            $payload['measurements'][0]['engines']['mysql']['median'],
            'a mocked search cannot take a second - this is elapsed time, not a timestamp'
        );
        $this->assertArrayHasKey('host', $payload);
        $this->assertArrayHasKey('recordedAt', $payload);
        $this->assertStringContainsString('Raw measurements written to run.json', $this->display());
    }

    public function testAMissingComparisonFileIsAWarningNotACrash(): void
    {
        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand(['--terms' => 'jacke', '--compare' => 'gone.json'])
        );
        $this->assertStringContainsString(
            'Cannot read gone.json - nothing to compare against.',
            $this->display()
        );
    }

    public function testAFileThatIsNotABenchmarkIsAWarningToo(): void
    {
        $this->command->files['notes.txt'] = 'just some notes';

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand(['--terms' => 'jacke', '--compare' => 'notes.txt'])
        );
        $this->assertStringContainsString('notes.txt is not a benchmark file', $this->display());
    }

    /**
     * Scenarios are matched by label, so a run given different terms has
     * nothing to compare - and says so instead of printing an empty table.
     */
    public function testARunWithOtherTermsFindsNothingToCompare(): void
    {
        $this->command->files['other.json'] = (string) json_encode([
            'host' => 'buildserver',
            'measurements' => [
                ['scenario' => 'search "hose"', 'engines' => ['mysql' => ['median' => 10.0]]],
            ],
        ]);

        $this->runCommand(['--terms' => 'jacke', '--compare' => 'other.json']);

        $this->assertStringContainsString('Against buildserver', $this->display());
        $this->assertStringContainsString('No scenario in common', $this->display());
    }

    public function testAMatchingScenarioIsComparedAgainstTheOtherMachine(): void
    {
        // Something for the clock to catch: a factor needs both sides to be
        // above zero, and a mocked engine is otherwise instant.
        $this->mysql->delayMicroseconds = 2000;
        $this->command->files['other.json'] = (string) json_encode([
            'host' => 'buildserver',
            'recordedAt' => '2026-08-30T10:00:00+00:00',
            'measurements' => [
                ['scenario' => 'search "jacke"', 'engines' => ['mysql' => ['median' => 50.0]]],
            ],
        ]);

        $this->runCommand(['--terms' => 'jacke', '--compare' => 'other.json']);

        $display = $this->display();

        $this->assertStringContainsString('Against buildserver (2026-08-30T10:00:00+00:00)', $display);
        $this->assertStringContainsString('scenario engine this run buildserver this run is', $display);
        $this->assertMatchesRegularExpression(
            '/search "jacke" mysql [\\d.,]+ ms 50\\.0 ms [\\d.,]+x/',
            $display,
            'the scenario, both numbers and the factor between them'
        );
        $this->assertStringContainsString('50.0 ms', $display, 'the other machine\'s number');
        $this->assertStringContainsString('A factor above 1.00x means this machine is faster.', $display);
    }

    /**
     * Files written before the context was added carried the bare measurement
     * list, and they still have to be readable.
     */
    public function testAFileWithoutContextIsStillComparable(): void
    {
        $this->command->files['old.json'] = (string) json_encode([
            ['scenario' => 'search "jacke"', 'engines' => ['mysql' => ['median' => 50.0]]],
        ]);

        $this->runCommand(['--terms' => 'jacke', '--compare' => 'old.json']);

        $display = $this->display();

        $this->assertStringContainsString('Against the other run', $display);
        $this->assertStringNotContainsString('No scenario in common', $display);
    }

    // ---------------------------------------------------------------
    // the statistics
    // ---------------------------------------------------------------

    /**
     * The median is what gets compared and p95 stands next to it, because a
     * search page is judged by its bad days. Both are taken from the sorted
     * runs by position - no interpolation, so the reported number is one that
     * actually happened.
     *
     * @dataProvider percentileProvider
     *
     * @param float[] $sorted
     */
    public function testWhichRunAPercentilePicks(array $sorted, float $percentile, float $expected): void
    {
        $this->assertSame($expected, $this->command->percentileOf($sorted, $percentile));
    }

    /**
     * @return array<string, array{float[], float, float}>
     */
    public function percentileProvider(): array
    {
        return [
            'the median of five runs is the third' => [[1.0, 2.0, 3.0, 4.0, 5.0], 0.5, 3.0],
            'the median of four runs is the second' => [[1.0, 2.0, 3.0, 4.0], 0.5, 2.0],
            'p95 of five runs is the slowest' => [[1.0, 2.0, 3.0, 4.0, 5.0], 0.95, 5.0],
            'p95 of twenty runs is the nineteenth' => [
                [
                    1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0,
                    11.0, 12.0, 13.0, 14.0, 15.0, 16.0, 17.0, 18.0, 19.0, 20.0,
                ],
                0.95,
                19.0,
            ],
            'a single run is every percentile of itself' => [[7.0], 0.5, 7.0],
            'a single run is its own p95' => [[7.0], 0.95, 7.0],
            // Nothing measured is not an error here - the caller has already
            // reported the engine as having answered nothing.
            'nothing measured is zero' => [[], 0.5, 0.0],
        ];
    }

    public function testAnUnreadableFileIsTreatedAsMissing(): void
    {
        $this->command->files['locked.json'] = 'anything';
        $this->command->unreadable = ['locked.json'];

        $this->runCommand(['--terms' => 'jacke', '--compare' => 'locked.json']);

        $this->assertStringContainsString('Cannot read locked.json', $this->display());
    }
}
