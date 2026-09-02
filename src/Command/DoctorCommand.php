<?php
declare(strict_types=1);

namespace foun10\EasySearch\Command;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\SearchEngineInterface;
use foun10\EasySearch\Index\DictionaryBuilder;
use foun10\EasySearch\Index\MySql\IndexTables;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Why is the search slow here, and what would fix it.
 *
 * Written after an afternoon of finding out the hard way. The same search that
 * took 505 ms locally took 10.4 seconds on the staging server, and every guess
 * about the query was wrong: the server was running the default 128 MB InnoDB
 * buffer pool against 550 MB of index tables. Raising it to 2 GB - one setting,
 * nothing else - took that search to 236 ms. Nothing in the shop said so, which
 * is what this command is for.
 *
 * Every finding below came out of a measurement rather than a best practice
 * list, and each one names the number it was derived from, because a hint
 * nobody can check is a hint nobody will act on.
 *
 * Read only. Safe to run against production.
 */
class DoctorCommand extends Command
{
    /**
     * Severities, in the order they are worth reading.
     */
    protected const PROBLEM = 'problem';
    protected const HINT = 'hint';
    protected const OK = 'ok';

    /**
     * Below this the bulk INSERT of a rebuild batch gets uncomfortable: a
     * document averages about a kilobyte of search text, and the admin reindex
     * may ask for 2,000 of them in one statement.
     */
    protected const MIN_PACKET_MB = 8;

    /**
     * A term this long or shorter cannot enter an InnoDB fulltext index at the
     * default minimum, and drops to a LIKE scan.
     */
    protected const SHORT_TERM_LENGTH = 2;

    /**
     * @var array<int, array{severity: string, headline: string, detail: string}>
     */
    protected array $findings = [];

    public function __construct(
        protected ShopLanguages $shopLanguages,
        protected IndexTables $tables
    ) {
        parent::__construct(null);
    }

    public function configure(): void
    {
        $this
            ->setName('foun10:easysearch:doctor')
            ->setDescription('Checks the database server and the index, and says what to do about what it finds')
            ->addOption('shop-id', null, InputOption::VALUE_OPTIONAL, 'Only this shop; default is every shop')
            ->addOption('lang-id', null, InputOption::VALUE_OPTIONAL, 'Only this language; default is every active one')
            ->addOption(
                'term',
                null,
                InputOption::VALUE_OPTIONAL,
                'Term to time a search with; by default the most frequent word of the indexed catalogue'
            )
            ->addOption('no-timing', null, InputOption::VALUE_NONE, 'Skip the timed search')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit non-zero when something needs doing');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $style->title('foun10 EasySearch - server and index check');

        $variables = $this->readServerVariables();
        $this->reportServer($variables, $style);

        $scopes = $this->resolveScopes($input);
        $footprint = $this->reportIndex($scopes, $style);

        $this->checkBufferPool($variables, $footprint);
        $this->checkPacketSize($variables);
        $this->checkShortTerms($variables, $scopes);
        $this->checkFacetPath($scopes);
        $this->checkFreshness($scopes);
        $this->checkLeftovers($scopes);

        if (!$input->getOption('no-timing')) {
            $term = $input->getOption('term');
            $this->measure($scopes, $term === null ? null : (string) $term, $style);
        }

        $this->reportFindings($style);

        return $input->getOption('strict') && $this->hasProblems()
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    protected function readServerVariables(): array
    {
        $names = [
            'version',
            'innodb_buffer_pool_size',
            'innodb_ft_min_token_size',
            'max_allowed_packet',
        ];

        $variables = [];

        foreach ($names as $name) {
            // Through fetchAll(), which already answers a database that cannot
            // be reached as empty - and empty reads as "unknown" here.
            $rows = $this->fetchAll("SHOW VARIABLES LIKE '" . $name . "'");
            $variables[$name] = (string) ($rows[0]['Value'] ?? '');
        }

        return $variables;
    }

    /**
     * @param array<string, string> $variables
     */
    protected function reportServer(array $variables, SymfonyStyle $style): void
    {
        $style->section('Server');
        $style->table(
            ['setting', 'value'],
            [
                ['version', $variables['version'] ?: 'unknown'],
                ['innodb_buffer_pool_size', $this->formatBytes((int) $variables['innodb_buffer_pool_size'])],
                ['innodb_ft_min_token_size', $variables['innodb_ft_min_token_size'] ?: '-'],
                ['max_allowed_packet', $this->formatBytes((int) $variables['max_allowed_packet'])],
            ]
        );
    }

    /**
     * What the index holds, and how much room it takes.
     *
     * @param array<int, array{shopId: int, langId: int}> $scopes
     *
     * @return int Bytes of every search table of every shop in the run
     */
    protected function reportIndex(array $scopes, SymfonyStyle $style): int
    {
        $style->section('Index');

        $rows = [];
        $total = 0;

        foreach ($this->getTablesOfScopes($scopes) as $table) {
            $stats = $this->getTableStats($table);

            if ($stats === null) {
                $rows[] = [$table, 'missing', '-', '-'];

                continue;
            }

            $total += $stats['bytes'];
            $rows[] = [
                $table,
                'present',
                number_format((float) $stats['rows']),
                $this->formatBytes((int) $stats['bytes']),
            ];
        }

        $style->table(['table', 'state', 'rows (estimated)', 'size'], $rows);
        $style->text('Search tables together: ' . $this->formatBytes($total));

        return $total;
    }

    /**
     * The one setting that decided everything on staging.
     *
     * @param array<string, string> $variables
     */
    protected function checkBufferPool(array $variables, int $footprint): void
    {
        $pool = (int) $variables['innodb_buffer_pool_size'];

        if ($pool <= 0 || $footprint <= 0) {
            return;
        }

        // The pool also holds the shop's own tables - articles, categories,
        // seo - so matching the index alone is the floor, not the target.
        if ($pool >= $footprint * 2) {
            $this->add(
                self::OK,
                'The InnoDB buffer pool has room for the index',
                sprintf(
                    'Pool %s against %s of search tables.',
                    $this->formatBytes($pool),
                    $this->formatBytes($footprint)
                )
            );

            return;
        }

        $suggested = $this->roundUpToGigabytes($footprint * 2);

        $this->add(
            $pool < $footprint ? self::PROBLEM : self::HINT,
            'The InnoDB buffer pool is smaller than the search index needs',
            sprintf(
                "Pool %s against %s of search tables, so facet queries read pages from disk.\n"
                . "    Set innodb_buffer_pool_size to at least %s and restart the database.\n"
                . '    Measured on staging: the same search went from 10,368 ms to 236 ms on this change alone.',
                $this->formatBytes($pool),
                $this->formatBytes($footprint),
                $this->formatBytes($suggested)
            )
        );
    }

    /**
     * @param array<string, string> $variables
     */
    protected function checkPacketSize(array $variables): void
    {
        $packet = (int) $variables['max_allowed_packet'];

        if ($packet <= 0 || $packet >= self::MIN_PACKET_MB * 1024 * 1024) {
            return;
        }

        $this->add(
            self::HINT,
            'max_allowed_packet is small for a rebuild',
            sprintf(
                "It is %s. A rebuild writes up to 2,000 documents in one INSERT, about 3 MB.\n"
                . '    Raise it to %d MB, or lower the batch size with --batch-size.',
                $this->formatBytes($packet),
                self::MIN_PACKET_MB
            )
        );
    }

    /**
     * Terms the fulltext index will not hold.
     *
     * Rarely a theoretical worry. Catalogues built on size codes, ERP short
     * names or two-letter abbreviations routinely have such a word at the very
     * top of the frequency list, and then the shop's most likely search is the
     * one query that cannot use the index. Hence the frequency in brackets
     * below - it says whether this matters here.
     *
     * @param array<string, string>                      $variables
     * @param array<int, array{shopId: int, langId: int}> $scopes
     */
    protected function checkShortTerms(array $variables, array $scopes): void
    {
        $minimum = (int) $variables['innodb_ft_min_token_size'];

        if ($minimum <= self::SHORT_TERM_LENGTH) {
            return;
        }

        $shopIds = [];

        foreach ($scopes as $scope) {
            $shopIds[(int) $scope['shopId']] = true;
        }

        // Grouped by term: the dictionary holds a row per shop and language, and
        // a list naming "bh" four times says less than one naming it once.
        $rows = $this->fetchAll(
            'SELECT FOUN10TERMRAW AS term, SUM(FOUN10FREQUENCY) AS frequency
             FROM ' . DictionaryBuilder::TABLE . '
             WHERE CHAR_LENGTH(FOUN10TERM) < :minimum
                AND OXSHOPID IN (' . implode(', ', array_keys($shopIds)) . ')
             GROUP BY FOUN10TERM
             ORDER BY frequency DESC
             LIMIT 5',
            [':minimum' => $minimum]
        );

        if ($rows === []) {
            return;
        }

        $examples = [];

        foreach ($rows as $row) {
            $examples[] = sprintf('%s (%s)', $row['term'], number_format((float) $row['frequency']));
        }

        $this->add(
            self::HINT,
            'Short terms cannot use the fulltext index',
            sprintf(
                "innodb_ft_min_token_size is %d, so these words fall back to a LIKE scan: %s.\n"
                . "    The number in brackets is how often the catalogue uses the word.\n"
                . '    Set innodb_ft_min_token_size=2, restart, and rebuild the index.',
                $minimum,
                implode(', ', $examples)
            )
        );
    }

    /**
     * Facets counted from the variant rows instead of the product level table.
     *
     * @param array<int, array{shopId: int, langId: int}> $scopes
     */
    protected function checkFacetPath(array $scopes): void
    {
        foreach ($scopes as $scope) {
            $attributes = $this->countScope($this->tables->attribute($scope['shopId']), $scope);

            if ($attributes === 0) {
                continue;
            }

            if ($this->countScope($this->tables->attributeGroup($scope['shopId']), $scope) > 0) {
                continue;
            }

            $this->add(
                self::PROBLEM,
                sprintf('Shop %d, language %d counts facets the slow way', $scope['shopId'], $scope['langId']),
                "The product level table is empty while attribute rows exist, so every facet count is\n"
                . "    computed from the variant rows - measured at three to eleven times the cost.\n"
                . '    A full rebuild derives it: vendor/bin/oe-console foun10:easysearch:reindex'
            );
        }
    }

    /**
     * An index older than the configuration it was built from.
     *
     * @param array<int, array{shopId: int, langId: int}> $scopes
     */
    protected function checkFreshness(array $scopes): void
    {
        foreach ($scopes as $scope) {
            $index = $this->tables->index($scope['shopId']);

            if (!$this->tables->exists($index)) {
                $this->add(
                    self::PROBLEM,
                    sprintf('Shop %d has no index at all', $scope['shopId']),
                    "The module reports itself unavailable and the shop serves its own search - no error,\n"
                    . '    just worse results. Build it: vendor/bin/oe-console foun10:easysearch:reindex'
                );

                continue;
            }

            $indexedAt = (string) $this->scalar('SELECT MAX(OXTIMESTAMP) FROM ' . $index
                . ' WHERE OXSHOPID = ' . $scope['shopId'] . ' AND FOUN10LANGID = ' . $scope['langId']);

            $configuredAt = (string) $this->scalar('SELECT MAX(OXTIMESTAMP) FROM ' . AttributeConfiguration::TABLE
                . ' WHERE OXSHOPID = ' . $scope['shopId']);

            if ($indexedAt === '' || $configuredAt === '' || $configuredAt <= $indexedAt) {
                continue;
            }

            $this->add(
                self::HINT,
                sprintf('Shop %d was configured after it was last indexed', $scope['shopId']),
                sprintf(
                    "Attributes changed %s, index written %s. Which attributes are facets is decided\n"
                    . '    while documents are written, so the sidebar shows the old set until a rebuild.',
                    $configuredAt,
                    $indexedAt
                )
            );
        }
    }

    /**
     * Shadow tables nobody swapped in.
     *
     * @param array<int, array{shopId: int, langId: int}> $scopes
     */
    protected function checkLeftovers(array $scopes): void
    {
        $leftovers = [];

        foreach ($this->getTablesOfScopes($scopes) as $table) {
            foreach ([$this->tables->shadow($table), $this->tables->retired($table)] as $candidate) {
                if ($this->getTableStats($candidate) !== null) {
                    $leftovers[] = $candidate;
                }
            }
        }

        if ($leftovers === []) {
            return;
        }

        $this->add(
            self::HINT,
            'A rebuild left tables behind',
            sprintf(
                "%s. A _tmp or _old table means a rebuild died before its swap.\n"
                . '    The next full rebuild clears them; nothing reads them meanwhile.',
                implode(', ', $leftovers)
            )
        );
    }

    /**
     * One timed search, split into the parts that can each be slow for their
     * own reason.
     *
     * @param array<int, array{shopId: int, langId: int}> $scopes
     */
    protected function measure(array $scopes, ?string $term, SymfonyStyle $style): void
    {
        $scope = $scopes[0] ?? null;

        if ($scope === null) {
            return;
        }

        $term ??= $this->getBusiestTerm($scope);

        if ($term === null || $term === '') {
            $style->text('No term to time a search with - the index holds none yet. Pass --term to name one.');

            return;
        }

        $style->section(sprintf('One search for "%s" in shop %d, language %d', $term, $scope['shopId'], $scope['langId']));

        try {
            $engine = $this->getEngine();
            $query = new SearchQuery($term, $scope['shopId'], $scope['langId']);

            // One unmeasured run: the first search of a process pays for caches
            // every later one finds warm.
            $engine->search($query);

            $started = $this->now();
            $result = $engine->search($query);
            $elapsed = ($this->now() - $started) * 1000;
        } catch (Throwable $exception) {
            $style->warning('Could not run a search: ' . $exception->getMessage());

            return;
        }

        $style->text(sprintf(
            '%s hits, %d facets, %s',
            number_format($result->getTotalCount()),
            count($result->getFacets()),
            $this->formatMs($elapsed)
        ));

        if ($elapsed < 500) {
            return;
        }

        $this->add(
            $elapsed > 2000 ? self::PROBLEM : self::HINT,
            'A search takes longer than it should',
            sprintf(
                "%s for \"%s\". On a warm server a catalogue of this size answers in a few hundred milliseconds.\n"
                . '    Check the buffer pool first - it is what this usually is.',
                $this->formatMs($elapsed),
                $term
            )
        );
    }

    /**
     * The word the indexed catalogue uses most in this scope.
     *
     * A timing is only worth reading if the term is one the shop would really
     * be asked for, and no invented word is: the point of the measurement is
     * how this catalogue behaves, so it names the term itself.
     *
     * @param array{shopId: int, langId: int} $scope
     */
    protected function getBusiestTerm(array $scope): ?string
    {
        $rows = $this->fetchAll(
            'SELECT FOUN10TERMRAW AS term
             FROM ' . DictionaryBuilder::TABLE . '
             WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId
             ORDER BY FOUN10FREQUENCY DESC
             LIMIT 1',
            [':shopId' => $scope['shopId'], ':langId' => $scope['langId']]
        );

        return $rows === [] ? null : (string) $rows[0]['term'];
    }

    protected function reportFindings(SymfonyStyle $style): void
    {
        $style->section('Findings');

        if ($this->findings === []) {
            $style->success('Nothing to report.');

            return;
        }

        foreach ([self::PROBLEM, self::HINT, self::OK] as $severity) {
            foreach ($this->findings as $finding) {
                if ($finding['severity'] !== $severity) {
                    continue;
                }

                $style->writeln(sprintf(
                    '  <%s>[%s]</> %s',
                    $severity === self::PROBLEM ? 'error' : ($severity === self::HINT ? 'comment' : 'info'),
                    $severity,
                    $finding['headline']
                ));
                $style->writeln('    ' . $finding['detail']);
                $style->newLine();
            }
        }
    }

    protected function add(string $severity, string $headline, string $detail): void
    {
        $this->findings[] = ['severity' => $severity, 'headline' => $headline, 'detail' => $detail];
    }

    protected function hasProblems(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding['severity'] === self::PROBLEM) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{shopId: int, langId: int}> $scopes
     *
     * @return string[]
     */
    protected function getTablesOfScopes(array $scopes): array
    {
        $tables = [];

        foreach ($scopes as $scope) {
            foreach (
                [
                    $this->tables->index($scope['shopId']),
                    $this->tables->attribute($scope['shopId']),
                    $this->tables->attributeGroup($scope['shopId']),
                    $this->tables->category($scope['shopId']),
                ] as $table
            ) {
                $tables[$table] = true;
            }
        }

        $tables[DictionaryBuilder::TABLE] = true;

        return array_keys($tables);
    }

    /**
     * @return array{rows: int, bytes: int}|null
     */
    protected function getTableStats(string $table): ?array
    {
        // Both columns are aliased, table_rows included: information_schema
        // hands its own columns back uppercased, so an unaliased table_rows
        // arrives as TABLE_ROWS and every row count reads as null.
        $rows = $this->fetchAll(
            "SELECT table_rows AS estimate, data_length + index_length AS bytes
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table",
            [':table' => $table]
        );

        if ($rows === []) {
            return null;
        }

        return ['rows' => (int) $rows[0]['estimate'], 'bytes' => (int) $rows[0]['bytes']];
    }

    /**
     * @param array{shopId: int, langId: int} $scope
     */
    protected function countScope(string $table, array $scope): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM ' . $table
            . ' WHERE OXSHOPID = ' . $scope['shopId'] . ' AND FOUN10LANGID = ' . $scope['langId']
        );
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $parameters = []): array
    {
        try {
            return $this->query($sql, $parameters);
        } catch (Throwable $exception) {
            // A table that is not there answers as empty: this command exists
            // to describe a broken installation, not to fall over on one.
            return [];
        }
    }

    protected function scalar(string $sql): mixed
    {
        try {
            return $this->queryScalar($sql);
        } catch (Throwable $exception) {
            return '';
        }
    }

    /**
     * The database, on its own so the swallowing above stays exercisable.
     *
     * Both seams below carry rows and scalars rather than a connection: a test
     * standing in for them needs no OXID class loaded, and the catch that turns
     * a missing table into an empty answer is then a decision a test can drive
     * rather than a branch nothing reaches.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<int, array<string, mixed>>
     */
    protected function query(string $sql, array $parameters = []): array
    {
        return DatabaseHelper::fetchAll($sql, $parameters);
    }

    protected function queryScalar(string $sql): mixed
    {
        return DatabaseProvider::getDb()->getOne($sql);
    }

    /**
     * The clock, on its own because the two thresholds below it - half a second
     * for a hint, two seconds for a problem - are the point of the measurement.
     * Reaching them for real would mean a test that sleeps for two seconds.
     */
    protected function now(): float
    {
        return microtime(true);
    }

    protected function getEngine(): SearchEngineInterface
    {
        /** @var SearchEngineInterface $engine */
        $engine = \OxidEsales\EshopCommunity\Internal\Container\ContainerFactory::getInstance()
            ->getContainer()
            ->get(SearchEngineInterface::class);

        return $engine;
    }

    /**
     * @return array<int, array{shopId: int, langId: int}>
     */
    protected function resolveScopes(InputInterface $input): array
    {
        $shopIdOption = $input->getOption('shop-id');
        $langIdOption = $input->getOption('lang-id');

        $shopIds = $shopIdOption !== null
            ? [(int) $shopIdOption]
            : $this->getAllShopIds();

        $scopes = [];

        foreach ($shopIds as $shopId) {
            $langIds = $langIdOption !== null
                ? [(int) $langIdOption]
                : $this->shopLanguages->getActiveIds($shopId);

            foreach ($langIds as $langId) {
                $scopes[] = ['shopId' => $shopId, 'langId' => (int) $langId];
            }
        }

        return $scopes;
    }

    /**
     * Every shop of the installation, for a run that names none.
     *
     * @return int[]
     */
    protected function getAllShopIds(): array
    {
        return array_map('intval', (array) Registry::getConfig()->getShopIds());
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '-';
        }

        if ($bytes >= 1024 * 1024 * 1024) {
            return sprintf('%.1f GB', $bytes / 1024 / 1024 / 1024);
        }

        // Below a megabyte in kilobytes rather than as "0 MB": a fresh shop's
        // tables are all under one, and a table listed as present at zero size
        // reads like a bug.
        return $bytes >= 1024 * 1024
            ? sprintf('%d MB', (int) round($bytes / 1024 / 1024))
            : sprintf('%d KB', (int) round($bytes / 1024));
    }

    protected function formatMs(float $milliseconds): string
    {
        return $milliseconds >= 1000
            ? sprintf('%.2f s', $milliseconds / 1000)
            : sprintf('%.0f ms', $milliseconds);
    }

    protected function roundUpToGigabytes(int $bytes): int
    {
        $gigabyte = 1024 * 1024 * 1024;

        return (int) (max(1, ceil($bytes / $gigabyte)) * $gigabyte);
    }
}
