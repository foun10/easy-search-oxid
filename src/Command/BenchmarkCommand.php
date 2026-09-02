<?php
declare(strict_types=1);

namespace foun10\EasySearch\Command;

use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Engine\EngineLocator;
use foun10\EasySearch\Engine\Query\FacetFilter;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Result\SearchResult;
use foun10\EasySearch\Engine\SearchEngineInterface;
use foun10\EasySearch\Index\DictionaryBuilder;
use foun10\EasySearch\Log\Period;
use foun10\EasySearch\Log\SearchLog;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Runs the same searches through every connector and prints what they cost.
 *
 * Built for the decision the module was designed to leave open: whether the
 * MySQL engine stays or Meilisearch takes over. That decision needs two things
 * a stopwatch alone does not give.
 *
 * The first is a like-for-like measurement. Both engines answer the identical
 * SearchQuery, including facets, through the identical interface, and the run
 * is repeated so a single slow query does not decide anything - the median is
 * what gets compared, with p95 next to it because a search page is judged by
 * its bad days.
 *
 * The second is whether they agree. An engine that is faster because it finds
 * less is not faster. Every scenario therefore also reports the hit count of
 * each engine and how much of the first result page they share, so a large gap
 * shows up as a difference in results rather than hiding inside a good timing.
 *
 * Both indexes have to be built for this to say anything:
 *
 *   vendor/bin/oe-console foun10:easysearch:reindex --engine=mysql
 *   vendor/bin/oe-console foun10:easysearch:reindex --engine=meilisearch
 */
class BenchmarkCommand extends Command
{
    protected const DEFAULT_RUNS = 5;
    protected const DEFAULT_LIMIT = 24;

    /**
     * How many terms to take from the shop's own data when none were named.
     */
    protected const DEFAULT_SAMPLE = 20;

    public function __construct(
        protected EngineLocator $engineLocator,
        protected SearchLog $searchLog
    ) {
        parent::__construct(null);
    }

    public function configure(): void
    {
        $this
            ->setName('foun10:easysearch:benchmark')
            ->setDescription('Compares the search connectors on speed and on what they find')
            // No defaults on these two: the OXID console defines --shop-id
            // globally, and Symfony only tolerates a redefinition that matches
            // the existing one exactly - a default here makes the command
            // refuse to start with "an option named shop-id already exists".
            ->addOption('shop-id', null, InputOption::VALUE_OPTIONAL, 'Shop to search in (default 1)')
            ->addOption('lang-id', null, InputOption::VALUE_OPTIONAL, 'Language to search in (default 0)')
            ->addOption(
                'engines',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma separated engines to compare',
                'mysql,meilisearch'
            )
            ->addOption('runs', null, InputOption::VALUE_OPTIONAL, 'Measured runs per scenario', (string) self::DEFAULT_RUNS)
            ->addOption(
                'compare',
                null,
                InputOption::VALUE_OPTIONAL,
                'A --json file from an earlier run, to print this run against it - the way to compare two machines'
            )
            ->addOption('terms', null, InputOption::VALUE_OPTIONAL, 'Comma separated search terms; by default the terms this shop actually logged are used')
            ->addOption(
                'terms-from-log',
                null,
                InputOption::VALUE_OPTIONAL,
                'Use the N most searched terms customers actually typed'
            )
            ->addOption(
                'terms-from-dictionary',
                null,
                InputOption::VALUE_OPTIONAL,
                'Take the N most frequent indexed terms instead of the logged ones'
            )
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Hits per page', (string) self::DEFAULT_LIMIT)
            ->addOption('category', null, InputOption::VALUE_OPTIONAL, 'Also measure a category listing, by category OXID')
            ->addOption(
                'no-filter-scenario',
                null,
                InputOption::VALUE_NONE,
                'Skip the scenario with a facet selected, which is the expensive one'
            )
            ->addOption('json', null, InputOption::VALUE_OPTIONAL, 'Write the raw measurements to this file');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $shopId = (int) ($input->getOption('shop-id') ?? 1);
        $langId = (int) ($input->getOption('lang-id') ?? 0);
        $runs = max(1, (int) $input->getOption('runs'));
        $limit = max(1, (int) $input->getOption('limit'));

        try {
            $engines = $this->resolveEngines($input);
        } catch (InvalidArgumentException $exception) {
            $style->error($exception->getMessage());

            return Command::FAILURE;
        }

        $style->title('Search connector benchmark');
        $style->text(sprintf('Shop %d, language %d, %d runs per scenario, %d hits per page', $shopId, $langId, $runs, $limit));

        if (!$this->reportAvailability($engines, $shopId, $langId, $style)) {
            return Command::FAILURE;
        }

        $scenarios = $this->buildScenarios($input, $shopId, $langId, $limit, $engines);
        $measurements = [];

        foreach ($scenarios as $scenario) {
            $measurements[] = $this->measure($scenario, $engines, $runs, $style);
        }

        $this->reportSummary($measurements, array_keys($engines), $style);

        $comparePath = $input->getOption('compare');

        if ($comparePath !== null) {
            $this->reportComparison($measurements, (string) $comparePath, $style);
        }

        $jsonPath = $input->getOption('json');

        if ($jsonPath !== null) {
            // Written with its context, not as bare numbers: a file that does
            // not say which machine, which shop and which terms produced it is
            // worthless the moment there are two of them.
            $payload = [
                'host' => php_uname('n'),
                'recordedAt' => date('c'),
                'shopId' => $shopId,
                'langId' => $langId,
                'runs' => $runs,
                'limit' => $limit,
                'engines' => array_keys($engines),
                'measurements' => $measurements,
            ];

            $this->writeFile(
                (string) $jsonPath,
                (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            $style->text('Raw measurements written to ' . $jsonPath);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, SearchEngineInterface> $engines
     */
    protected function reportAvailability(array $engines, int $shopId, int $langId, SymfonyStyle $style): bool
    {
        $rows = [];
        $available = 0;

        foreach ($engines as $name => $engine) {
            $isAvailable = $engine->isAvailable($shopId, $langId);
            $available += $isAvailable ? 1 : 0;
            $rows[] = [$name, $isAvailable ? 'ready' : 'no index - run foun10:easysearch:reindex --engine=' . $name];
        }

        $style->table(['engine', 'state'], $rows);

        // Every engine named has to be able to answer. Two of them make it a
        // connector comparison, one makes it a measurement of this machine -
        // both are useless if the index behind them is missing.
        if ($available < count($engines)) {
            $style->error('Every engine named has to be indexed first - see the state column above');

            return false;
        }

        return true;
    }

    /**
     * @param array<string, SearchEngineInterface> $engines
     *
     * @return array<int, array{label: string, query: SearchQuery}>
     */
    protected function buildScenarios(
        InputInterface $input,
        int $shopId,
        int $langId,
        int $limit,
        array $engines
    ): array {
        $scenarios = [];

        foreach ($this->resolveTerms($input, $shopId, $langId) as $term) {
            $scenarios[] = [
                'label' => 'search "' . $term . '"',
                'query' => new SearchQuery($term, $shopId, $langId, [], SearchQuery::SORT_RELEVANCE, 0, $limit),
            ];
        }

        $categoryId = $input->getOption('category');

        if ($categoryId !== null) {
            $scenarios[] = [
                'label' => 'category listing',
                'query' => new SearchQuery(
                    '',
                    $shopId,
                    $langId,
                    [],
                    SearchQuery::SORT_RELEVANCE,
                    0,
                    $limit,
                    (string) $categoryId
                ),
            ];
        }

        if (!$input->getOption('no-filter-scenario')) {
            $filtered = $this->buildFilteredScenario($scenarios, $engines);

            if ($filtered !== null) {
                $scenarios[] = $filtered;
            }
        }

        return $scenarios;
    }

    /**
     * A scenario with one facet value selected.
     *
     * Worth its own row because it is the expensive shape for both connectors:
     * a selected facet has to be counted with its own selection removed, so it
     * can no longer share the query that answers all the others.
     *
     * The value is taken from a real result rather than configured, so the
     * benchmark keeps working on any catalogue.
     *
     * @param array<int, array{label: string, query: SearchQuery}> $scenarios
     * @param array<string, SearchEngineInterface>                 $engines
     *
     * @return array{label: string, query: SearchQuery}|null
     */
    protected function buildFilteredScenario(array $scenarios, array $engines): ?array
    {
        if ($scenarios === []) {
            return null;
        }

        $engine = reset($engines);
        $base = $scenarios[0]['query'];

        try {
            $result = $engine->search($base);
        } catch (Throwable $exception) {
            return null;
        }

        foreach ($result->getFacets() as $facet) {
            foreach ($facet->getValues() as $value) {
                if ($value->getCount() < 1) {
                    continue;
                }

                return [
                    'label' => sprintf('%s + filter %s', $scenarios[0]['label'], $value->getLabel()),
                    'query' => new SearchQuery(
                        $base->getTerm(),
                        $base->getShopId(),
                        $base->getLangId(),
                        [new FacetFilter($facet->getAttributeId(), [$value->getValueId()])],
                        $base->getSort(),
                        0,
                        $base->getLimit()
                    ),
                ];
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    protected function resolveTerms(InputInterface $input, int $shopId, int $langId): array
    {
        $terms = $input->getOption('terms');

        if ($terms !== null) {
            return array_values(array_filter(array_map('trim', explode(',', (string) $terms))));
        }

        // What customers actually type beats anything invented here, so this is
        // also what happens when no option was given at all - see
        // foun10:easysearch:log. A benchmark against a hand written list only
        // measures how the engines handle that list.
        $fromLog = $input->getOption('terms-from-log');
        $logged = $this->searchLog->getBenchmarkTerms(
            $shopId,
            $langId,
            Period::lastDays(90),
            max(1, (int) ($fromLog ?? self::DEFAULT_SAMPLE))
        );

        if ($logged !== []) {
            return $logged;
        }

        // Nothing logged yet - a fresh shop, or logging switched off. The
        // catalogue's own vocabulary is the next best load profile.
        $fromDictionary = $input->getOption('terms-from-dictionary');
        $sampled = $this->sampleTerms(
            $shopId,
            $langId,
            max(1, (int) ($fromDictionary ?? self::DEFAULT_SAMPLE))
        );

        if ($sampled !== []) {
            return $sampled;
        }

        throw new InvalidArgumentException(
            'No search terms available: the log is empty for the last 90 days and the dictionary '
            . 'has not been built yet. Run a reindex, or pass --terms=one,two,three.'
        );
    }

    /**
     * The most frequent terms of this shop's own catalogue.
     *
     * A better load profile than a hand written list when the question is how
     * an engine behaves on this data - these are the words the products are
     * actually described with.
     *
     * @return string[]
     */
    protected function sampleTerms(int $shopId, int $langId, int $limit): array
    {
        $sql = '
            SELECT FOUN10TERMRAW
            FROM ' . DictionaryBuilder::TABLE . '
            WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId AND FOUN10LENGTH >= 3
            ORDER BY FOUN10FREQUENCY DESC
            LIMIT ' . $limit;

        try {
            $rows = $this->fetchRows($sql, [':shopId' => $shopId, ':langId' => $langId]);
        } catch (Throwable $exception) {
            return [];
        }

        return array_map(static fn (array $row): string => (string) $row['FOUN10TERMRAW'], $rows);
    }

    /**
     * @param array{label: string, query: SearchQuery} $scenario
     * @param array<string, SearchEngineInterface>     $engines
     *
     * @return array<string, mixed>
     */
    protected function measure(array $scenario, array $engines, int $runs, SymfonyStyle $style): array
    {
        $style->section($scenario['label']);

        $perEngine = [];
        $topIds = [];
        $rows = [];

        foreach ($engines as $name => $engine) {
            // One unmeasured run first. Both engines cache - MySQL in its
            // buffer pool, Meilisearch in the page cache - and the first call
            // would otherwise measure the disk rather than the engine.
            $result = $this->runSearch($engine, $scenario['query']);
            $durations = [];

            for ($run = 0; $run < $runs; $run++) {
                $started = microtime(true);
                $result = $this->runSearch($engine, $scenario['query']);
                $durations[] = (microtime(true) - $started) * 1000;
            }

            sort($durations);

            $perEngine[$name] = [
                'hits' => $result?->getTotalCount() ?? 0,
                'facets' => $result === null ? 0 : count($result->getFacets()),
                'median' => $this->percentile($durations, 0.5),
                'p95' => $this->percentile($durations, 0.95),
                'min' => $durations[0] ?? 0.0,
                'max' => $durations[count($durations) - 1] ?? 0.0,
                'durations' => $durations,
            ];

            $topIds[$name] = $result?->getProductIds() ?? [];

            $rows[] = [
                $name,
                number_format($perEngine[$name]['hits']),
                $perEngine[$name]['facets'],
                $this->formatMs($perEngine[$name]['median']),
                $this->formatMs($perEngine[$name]['p95']),
                $this->formatMs($perEngine[$name]['min']),
                $this->formatMs($perEngine[$name]['max']),
            ];
        }

        $style->table(['engine', 'hits', 'facets', 'median', 'p95', 'min', 'max'], $rows);

        $agreement = $this->compareResults($topIds);

        if ($agreement !== null) {
            $style->text(sprintf(
                'First page: %d of %d products shared, %d in the same position',
                $agreement['shared'],
                $agreement['size'],
                $agreement['sameRank']
            ));
        }

        return [
            'scenario' => $scenario['label'],
            'engines' => $perEngine,
            'agreement' => $agreement,
        ];
    }

    protected function runSearch(SearchEngineInterface $engine, SearchQuery $query): ?SearchResult
    {
        try {
            return $engine->search($query);
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * How much of the first result page two engines have in common.
     *
     * Compared against the first engine on the list, which is the incumbent -
     * the question being asked is what changes if the other one takes over.
     *
     * @param array<string, string[]> $topIds
     *
     * @return array{size: int, shared: int, sameRank: int}|null
     */
    protected function compareResults(array $topIds): ?array
    {
        if (count($topIds) < 2) {
            return null;
        }

        $names = array_keys($topIds);
        $left = $topIds[$names[0]];
        $right = $topIds[$names[1]];
        $size = max(count($left), count($right));
        $sameRank = 0;

        foreach ($left as $position => $id) {
            if (($right[$position] ?? null) === $id) {
                $sameRank++;
            }
        }

        return [
            'size' => $size,
            'shared' => count(array_intersect($left, $right)),
            'sameRank' => $sameRank,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $measurements
     * @param string[]                         $engineNames
     */
    protected function reportSummary(array $measurements, array $engineNames, SymfonyStyle $style): void
    {
        $style->section('Summary');

        $totals = [];

        foreach ($measurements as $measurement) {
            foreach ($engineNames as $name) {
                $totals[$name] = ($totals[$name] ?? 0.0) + (float) ($measurement['engines'][$name]['median'] ?? 0.0);
            }
        }

        $baseline = $totals[$engineNames[0]] ?? 0.0;
        $rows = [];

        foreach ($totals as $name => $total) {
            $rows[] = [
                $name,
                $this->formatMs($total),
                $this->formatMs(count($measurements) > 0 ? $total / count($measurements) : 0.0),
                $total > 0 && $baseline > 0 ? sprintf('%.2fx', $baseline / $total) : '-',
            ];
        }

        $style->table(
            ['engine', 'sum of medians', 'per scenario', 'speed vs ' . $engineNames[0]],
            $rows
        );

        $style->text('A factor above 1.00x means faster than ' . $engineNames[0] . '.');
    }

    /**
     * This run against a file from another one.
     *
     * The point is comparing machines: the same terms, the same engine, one
     * measurement taken locally and one on the server. Scenarios are matched by
     * label, so both runs have to have been given the same terms - a run with
     * different terms simply finds nothing to compare and says so.
     *
     * @param array<int, array<string, mixed>> $measurements
     */
    protected function reportComparison(array $measurements, string $path, SymfonyStyle $style): void
    {
        $contents = $this->readFile($path);

        if ($contents === null) {
            $style->warning('Cannot read ' . $path . ' - nothing to compare against.');

            return;
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            $style->warning($path . ' is not a benchmark file.');

            return;
        }

        // Files written before this carried the bare measurement list.
        $other = $decoded['measurements'] ?? $decoded;
        $host = (string) ($decoded['host'] ?? 'the other run');
        $recorded = (string) ($decoded['recordedAt'] ?? '');

        $medians = [];

        foreach ((array) $other as $measurement) {
            foreach ((array) ($measurement['engines'] ?? []) as $name => $values) {
                $medians[$measurement['scenario'] . '|' . $name] = (float) ($values['median'] ?? 0.0);
            }
        }

        $rows = [];

        foreach ($measurements as $measurement) {
            foreach ((array) ($measurement['engines'] ?? []) as $name => $values) {
                $key = $measurement['scenario'] . '|' . $name;

                if (!isset($medians[$key])) {
                    continue;
                }

                $here = (float) ($values['median'] ?? 0.0);
                $there = $medians[$key];

                $rows[] = [
                    $measurement['scenario'],
                    $name,
                    $this->formatMs($here),
                    $this->formatMs($there),
                    $there > 0 && $here > 0 ? sprintf('%.2fx', $there / $here) : '-',
                ];
            }
        }

        $style->section('Against ' . $host . ($recorded !== '' ? ' (' . $recorded . ')' : ''));

        if ($rows === []) {
            $style->text('No scenario in common - run both sides with the same --terms.');

            return;
        }

        $style->table(['scenario', 'engine', 'this run', $host, 'this run is'], $rows);
        $style->text('A factor above 1.00x means this machine is faster.');
    }

    /**
     * @return array<string, SearchEngineInterface>
     */
    protected function resolveEngines(InputInterface $input): array
    {
        $names = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('engines')))));

        // One engine is a legitimate run: the comparison is then not between
        // two connectors but between two machines - the same measurement taken
        // on a laptop and on the server, brought together with --compare.
        if ($names === []) {
            throw new InvalidArgumentException('Name at least one engine, e.g. --engines=mysql');
        }

        $engines = [];

        foreach ($names as $name) {
            $engines[$name] = $this->engineLocator->get($name);
        }

        return $engines;
    }

    /**
     * @param float[] $sorted
     */
    protected function percentile(array $sorted, float $percentile): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (int) ceil($percentile * count($sorted)) - 1;

        return $sorted[max(0, min($index, count($sorted) - 1))];
    }

    protected function formatMs(float $milliseconds): string
    {
        return number_format($milliseconds, 1) . ' ms';
    }

    /*
     * The shop and the filesystem. Everything else - which scenarios are
     * measured, how the numbers are reduced, what counts as agreement between
     * two engines - is arithmetic over what the injected engines answered.
     */

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchRows(string $sql, array $parameters = []): array
    {
        return DatabaseHelper::fetchAll($sql, $parameters);
    }

    protected function writeFile(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
    }

    /**
     * Null when there is nothing readable there, which is the only distinction
     * the caller makes.
     */
    protected function readFile(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
