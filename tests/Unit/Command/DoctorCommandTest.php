<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Command;

use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Tests\Unit\Double\SpySearchEngine;
use foun10\EasySearch\Tests\Unit\Double\TestableDoctorCommand;
use foun10\EasySearch\Tests\Unit\Double\TestableIndexTables;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The doctor command.
 *
 * This command gives advice, and advice is only worth anything if it is right
 * about the installation it is looking at. So what the tests below pin is not
 * the wording but the derivation: which number produces which severity, where
 * the boundary between "fine" and "worth doing something about" sits, and -
 * just as important - when the command says nothing at all. A checker that
 * warns about a healthy server is worse than none, because the next real
 * warning gets ignored with it.
 *
 * The second theme is survival. This runs against installations that are
 * already broken - a shop with no index, a database that refuses half the
 * questions - and it has to describe them rather than fall over on them.
 */
class DoctorCommandTest extends TestCase
{
    private const INDEX = 'foun10easysearchindex_s1';
    private const ATTRIBUTE = 'foun10easysearchindexattribute_s1';
    private const ATTRIBUTE_GROUP = 'foun10easysearchindexattributegroup_s1';
    private const CATEGORY = 'foun10easysearchindexcategory_s1';
    private const DICTIONARY = 'foun10easysearchdictionary';
    private const CONFIGURATION = 'foun10easysearchattribute';

    private const MEGABYTE = 1024 * 1024;
    private const GIGABYTE = 1024 * 1024 * 1024;

    private TestableDoctorCommand $command;

    private TestableIndexTables $tables;

    private CommandTester $tester;

    /** @var array<int, int[]> Active language IDs per shop */
    private array $activeLanguages = [1 => [0]];

    protected function setUp(): void
    {
        $shopLanguages = $this->createMock(ShopLanguages::class);
        $shopLanguages->method('getActiveIds')->willReturnCallback(
            fn (?int $shopId = null): array => $this->activeLanguages[$shopId] ?? []
        );

        $this->tables = new TestableIndexTables();

        // A shop that has an index and nothing else worth saying about it: the
        // baseline is deliberately silent, so every finding a test sees is one
        // that test asked for.
        $this->tables->existing = [self::INDEX];

        $this->command = new TestableDoctorCommand($shopLanguages, $this->tables);
        $this->tester = new CommandTester($this->command);
    }

    /**
     * Runs the command without the timed search.
     *
     * The timing is the one part that needs an engine, and most of what this
     * command does has nothing to do with it - so it is off unless a test says
     * `'--no-timing' => false`.
     *
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options = []): int
    {
        return $this->tester->execute(array_merge(['--no-timing' => true], $options));
    }

    private function display(): string
    {
        return $this->tester->getDisplay();
    }

    /**
     * The display with its whitespace collapsed.
     *
     * SymfonyStyle wraps blocks to the terminal width and pads its tables, so a
     * sentence can arrive split across two lines - and an assertion on the
     * sentence has no business depending on that.
     */
    private function displayText(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->display()));
    }

    /**
     * Gives a table a size, which also makes it exist.
     */
    private function weigh(string $table, int $bytes, int $rows = 0): void
    {
        $this->command->tableStats[$table] = ['rows' => $rows, 'bytes' => $bytes];
    }

    /**
     * @param array<string, string> $overrides
     */
    private function serverReports(array $overrides = []): void
    {
        $this->command->serverVariables = array_merge(
            [
                'version' => '8.0.36',
                'innodb_buffer_pool_size' => (string) (2 * self::GIGABYTE),
                'innodb_ft_min_token_size' => '2',
                'max_allowed_packet' => (string) (64 * self::MEGABYTE),
            ],
            $overrides
        );
    }

    /**
     * The scopes the run actually walked, as "shopId/langId".
     *
     * Read back from the facet-path check, which asks exactly once per scope.
     *
     * @return string[]
     */
    private function checkedScopes(): array
    {
        $scopes = [];

        foreach ($this->command->scalarQueries as $sql) {
            $matched = preg_match(
                '/COUNT\(\*\) FROM \S*indexattribute_s\d+ WHERE OXSHOPID = (\d+) AND FOUN10LANGID = (\d+)/',
                $sql,
                $matches
            );

            if ($matched === 1) {
                $scopes[] = $matches[1] . '/' . $matches[2];
            }
        }

        return $scopes;
    }

    private function findingsBlock(): string
    {
        $text = $this->displayText();
        $position = strpos($text, 'Findings');

        return $position === false ? '' : substr($text, $position);
    }

    /**
     * A statement with its formatting whitespace collapsed, so an assertion can
     * be made on the whole of it rather than on a fragment - a fragment
     * survives almost any scrambling of the parts around it.
     */
    private static function collapse(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    // ---------------------------------------------------------------
    // which scopes a run covers
    // ---------------------------------------------------------------

    public function testWalksEveryShopAndEveryActiveLanguageWhenNothingNarrowsTheRun(): void
    {
        $this->command->allShopIds = [1, 2];
        $this->activeLanguages = [1 => [0, 1], 2 => [0]];

        $this->runCommand();

        $this->assertSame(['1/0', '1/1', '2/0'], $this->checkedScopes());
    }

    public function testShopIdNarrowsTheRunToThatShop(): void
    {
        $this->command->allShopIds = [1, 2];
        $this->activeLanguages = [1 => [0], 2 => [0, 1]];

        $this->runCommand(['--shop-id' => 2]);

        $this->assertSame(['2/0', '2/1'], $this->checkedScopes());
    }

    /**
     * An explicit language is honoured even where the shop does not serve it -
     * a language can be prepared before it goes live, and then this is the way
     * to look at it.
     */
    public function testLangIdIsHonouredEvenWhenTheShopDoesNotServeIt(): void
    {
        $this->activeLanguages = [1 => [0]];

        $this->runCommand(['--lang-id' => 3]);

        $this->assertSame(['1/3'], $this->checkedScopes());
    }

    public function testAShopWithNoActiveLanguageContributesNoScope(): void
    {
        $this->command->allShopIds = [1, 2];
        $this->activeLanguages = [1 => [0]];

        $this->runCommand();

        $this->assertSame(['1/0'], $this->checkedScopes());
    }

    // ---------------------------------------------------------------
    // what the server says about itself
    // ---------------------------------------------------------------

    public function testTheServerTableRepeatsWhatTheDatabaseReports(): void
    {
        $this->serverReports(['innodb_ft_min_token_size' => '3']);

        $this->runCommand();

        $text = $this->displayText();
        $this->assertStringContainsString('setting value', $text);
        $this->assertStringContainsString('version 8.0.36', $text);
        $this->assertStringContainsString('innodb_buffer_pool_size 2.0 GB', $text);
        $this->assertStringContainsString('innodb_ft_min_token_size 3', $text);
        $this->assertStringContainsString('max_allowed_packet 64 MB', $text);
    }

    /**
     * The three headings are what makes the output readable at all - a wall of
     * numbers with no section is a wall of numbers.
     */
    public function testTheReportIsHeadedAndSplitIntoItsThreeParts(): void
    {
        $this->serverReports();

        $this->runCommand();

        $text = $this->displayText();
        $this->assertStringContainsString('foun10 EasySearch - server and index check', $text);
        $this->assertStringContainsString('Server', $text);
        $this->assertStringContainsString('Index', $text);
        $this->assertStringContainsString('Findings', $text);
    }

    public function testEveryServerVariableIsAskedForByName(): void
    {
        $this->runCommand();

        $asked = array_values(array_filter(
            $this->command->queries,
            static fn (string $sql): bool => str_starts_with($sql, 'SHOW VARIABLES')
        ));

        $this->assertSame(
            [
                "SHOW VARIABLES LIKE 'version'",
                "SHOW VARIABLES LIKE 'innodb_buffer_pool_size'",
                "SHOW VARIABLES LIKE 'innodb_ft_min_token_size'",
                "SHOW VARIABLES LIKE 'max_allowed_packet'",
            ],
            $asked
        );
    }

    public function testAVersionTheServerDidNotReportShowsAsUnknown(): void
    {
        $this->serverReports(['version' => '']);

        $this->runCommand();

        $this->assertStringContainsString('version unknown', $this->displayText());
    }

    public function testATokenSizeTheServerDidNotReportShowsAsADash(): void
    {
        $this->serverReports(['innodb_ft_min_token_size' => '']);

        $this->runCommand();

        $this->assertStringContainsString('innodb_ft_min_token_size -', $this->displayText());
    }

    public function testASizeBelowAGigabyteIsShownInMegabytes(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => (string) (128 * self::MEGABYTE)]);

        $this->runCommand();

        $this->assertStringContainsString('innodb_buffer_pool_size 128 MB', $this->displayText());
    }

    // ---------------------------------------------------------------
    // what the index weighs
    // ---------------------------------------------------------------

    public function testEveryTableOfTheScopeIsWeighedIncludingTheSharedDictionary(): void
    {
        $this->runCommand();

        $weighed = [];

        foreach ($this->command->queries as $index => $sql) {
            if (str_contains($sql, 'information_schema.tables')) {
                $weighed[] = (string) $this->command->queryParameters[$index][':table'];
            }
        }

        foreach (
            [self::INDEX, self::ATTRIBUTE, self::ATTRIBUTE_GROUP, self::CATEGORY, self::DICTIONARY] as $table
        ) {
            $this->assertContains($table, $weighed);
        }
    }

    public function testATableTheDatabaseDoesNotHaveIsReportedMissing(): void
    {
        $this->runCommand();

        $this->assertStringContainsString(self::CATEGORY . ' missing - -', $this->displayText());
    }

    public function testATablePresentIsReportedWithItsRowCountAndSize(): void
    {
        $this->weigh(self::INDEX, 550 * self::MEGABYTE, 128456);

        $this->runCommand();

        $text = $this->displayText();
        $this->assertStringContainsString('table state rows (estimated) size', $text);
        $this->assertStringContainsString(self::INDEX . ' present 128,456 550 MB', $text);
    }

    /**
     * Sizes are reported to the nearest megabyte rather than truncated, so a
     * table is never quoted smaller than it is.
     */
    public function testASizeIsRoundedToTheNearestMegabyte(): void
    {
        $this->weigh(self::INDEX, (int) (100.4 * self::MEGABYTE));

        $this->runCommand();

        $this->assertStringContainsString('Search tables together: 100 MB', $this->displayText());
    }

    /**
     * A table under a megabyte - which is every table of a fresh shop - would
     * otherwise be listed as present at "0 MB", which reads like a bug.
     */
    public function testASizeUnderAMegabyteIsReportedInKilobytes(): void
    {
        $this->weigh(self::INDEX, 340 * 1024);

        $this->runCommand();

        $this->assertStringContainsString('Search tables together: 340 KB', $this->displayText());
    }

    public function testASizeOfAMegabyteIsReportedInMegabytes(): void
    {
        $this->weigh(self::INDEX, self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString('Search tables together: 1 MB', $this->displayText());
    }

    public function testASizeJustUnderAGigabyteIsStillReportedInMegabytes(): void
    {
        $this->weigh(self::INDEX, (int) (1023.5 * self::MEGABYTE));

        $this->runCommand();

        $this->assertStringContainsString('Search tables together: 1024 MB', $this->displayText());
    }

    public function testASizeOfAGigabyteIsReportedInGigabytes(): void
    {
        $this->weigh(self::INDEX, self::GIGABYTE);

        $this->runCommand();

        $this->assertStringContainsString('Search tables together: 1.0 GB', $this->displayText());
    }

    public function testTheFootprintIsTheSumOfEveryTableThatIsThere(): void
    {
        $this->weigh(self::INDEX, 400 * self::MEGABYTE);
        $this->weigh(self::CATEGORY, 100 * self::MEGABYTE);
        $this->weigh(self::DICTIONARY, 50 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString('Search tables together: 550 MB', $this->displayText());
    }

    /**
     * Two languages of one shop share its tables, and a table counted twice
     * would double the footprint the buffer pool advice is derived from.
     */
    public function testAShopIsWeighedOnceHoweverManyLanguagesItServes(): void
    {
        $this->activeLanguages = [1 => [0, 1, 2]];
        $this->weigh(self::INDEX, 300 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString('Search tables together: 300 MB', $this->displayText());
    }

    // ---------------------------------------------------------------
    // the buffer pool, which is what this command was written for
    // ---------------------------------------------------------------

    public function testAPoolSmallerThanTheIndexIsAProblem(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => (string) (128 * self::MEGABYTE)]);
        $this->weigh(self::INDEX, 550 * self::MEGABYTE);

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringContainsString('[problem] The InnoDB buffer pool is smaller than the search index needs', $text);
        $this->assertStringContainsString(
            'Pool 128 MB against 550 MB of search tables, so facet queries read pages from disk.'
            . ' Set innodb_buffer_pool_size to at least 2.0 GB and restart the database.'
            . ' Measured on staging: the same search went from 10,368 ms to 236 ms on this change alone.',
            $text
        );
    }

    /**
     * A pool that exactly matches the index is still short of what it needs -
     * the shop's own tables want room in the same pool.
     */
    public function testAPoolExactlyTheSizeOfTheIndexIsOnlyAHint(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => (string) (550 * self::MEGABYTE)]);
        $this->weigh(self::INDEX, 550 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString('[hint] The InnoDB buffer pool is smaller', $this->findingsBlock());
    }

    /**
     * The pool also holds the shop's own tables, so matching the index alone is
     * the floor rather than the target - which is why this range is a hint
     * instead of nothing at all.
     */
    public function testAPoolBetweenOnceAndTwiceTheIndexIsOnlyAHint(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => (string) (700 * self::MEGABYTE)]);
        $this->weigh(self::INDEX, 550 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString('[hint] The InnoDB buffer pool is smaller', $this->findingsBlock());
    }

    public function testAPoolWithTwiceTheRoomIsReportedAsFine(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => (string) (2 * self::GIGABYTE)]);
        $this->weigh(self::INDEX, 550 * self::MEGABYTE);

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringContainsString('[ok] The InnoDB buffer pool has room for the index', $text);
        $this->assertStringContainsString('Pool 2.0 GB against 550 MB of search tables.', $text);
    }

    public function testExactlyTwiceTheIndexIsAlreadyEnough(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => (string) (600 * self::MEGABYTE)]);
        $this->weigh(self::INDEX, 300 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString('[ok]', $this->findingsBlock());
    }

    public function testTheSuggestedPoolIsTwiceTheIndexRoundedUpToWholeGigabytes(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => (string) (128 * self::MEGABYTE)]);
        $this->weigh(self::INDEX, 700 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString(
            'Set innodb_buffer_pool_size to at least 2.0 GB and restart the database.',
            $this->findingsBlock()
        );
    }

    /**
     * The size stays arithmetic rather than a rule of thumb, which only shows
     * where the numbers are big enough for a rule of thumb to drift.
     */
    public function testAVeryLargeIndexIsAdvisedInWholeGigabytesToo(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => (string) (128 * self::MEGABYTE)]);
        $this->weigh(self::INDEX, 50 * self::GIGABYTE);

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringContainsString('Pool 128 MB against 50.0 GB of search tables', $text);
        $this->assertStringContainsString('to at least 100.0 GB', $text);
    }

    public function testASmallIndexStillSuggestsAWholeGigabyte(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => (string) (10 * self::MEGABYTE)]);
        $this->weigh(self::INDEX, 40 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString('to at least 1.0 GB', $this->findingsBlock());
    }

    /**
     * The advice names the measurement it came out of, because a hint nobody
     * can check is a hint nobody will act on.
     */
    public function testTheAdviceQuotesTheMeasurementItCameFrom(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => (string) (128 * self::MEGABYTE)]);
        $this->weigh(self::INDEX, 550 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString(
            'the same search went from 10,368 ms to 236 ms on this change alone',
            $this->findingsBlock()
        );
    }

    public function testNothingIsSaidAboutThePoolWhenTheServerDidNotReportIt(): void
    {
        $this->serverReports(['innodb_buffer_pool_size' => '']);
        $this->weigh(self::INDEX, 550 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringNotContainsString('buffer pool', $this->findingsBlock());
    }

    public function testNothingIsSaidAboutThePoolWhenThereIsNoIndexToCompareItTo(): void
    {
        $this->serverReports();

        $this->runCommand();

        $this->assertStringNotContainsString('buffer pool', $this->findingsBlock());
    }

    // ---------------------------------------------------------------
    // the packet size a rebuild needs
    // ---------------------------------------------------------------

    public function testAPacketTooSmallForARebuildIsAHint(): void
    {
        $this->serverReports(['max_allowed_packet' => (string) (4 * self::MEGABYTE)]);

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringContainsString('[hint] max_allowed_packet is small for a rebuild', $text);
        $this->assertStringContainsString(
            'It is 4 MB. A rebuild writes up to 2,000 documents in one INSERT, about 3 MB.'
            . ' Raise it to 8 MB, or lower the batch size with --batch-size.',
            $text
        );
    }

    public function testAPacketAHairUnderEightMegabytesIsStillTooSmall(): void
    {
        $this->serverReports(['max_allowed_packet' => (string) (8 * self::MEGABYTE - 8192)]);

        $this->runCommand();

        $this->assertStringContainsString('max_allowed_packet is small for a rebuild', $this->findingsBlock());
    }

    public function testExactlyEightMegabytesIsEnough(): void
    {
        $this->serverReports(['max_allowed_packet' => (string) (8 * self::MEGABYTE)]);

        $this->runCommand();

        $this->assertStringNotContainsString('max_allowed_packet is small', $this->findingsBlock());
    }

    public function testNothingIsSaidAboutThePacketWhenTheServerDidNotReportIt(): void
    {
        $this->serverReports(['max_allowed_packet' => '']);

        $this->runCommand();

        $this->assertStringNotContainsString('max_allowed_packet is small', $this->findingsBlock());
    }

    // ---------------------------------------------------------------
    // terms the fulltext index will not hold
    // ---------------------------------------------------------------

    public function testTermsBelowTheTokenSizeAreListedWithHowOftenTheCatalogueUsesThem(): void
    {
        $this->serverReports(['innodb_ft_min_token_size' => '3']);
        $this->command->shortTerms = [
            ['term' => 'xl', 'frequency' => 12400],
            ['term' => 'm', 'frequency' => 980],
        ];

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringContainsString('[hint] Short terms cannot use the fulltext index', $text);
        $this->assertStringContainsString(
            'innodb_ft_min_token_size is 3, so these words fall back to a LIKE scan: xl (12,400), m (980).'
            . ' The number in brackets is how often the catalogue uses the word.'
            . ' Set innodb_ft_min_token_size=2, restart, and rebuild the index.',
            $text
        );
    }

    public function testATokenSizeOfTwoNeedsNoAdviceAtAll(): void
    {
        $this->serverReports(['innodb_ft_min_token_size' => '2']);
        $this->command->shortTerms = [['term' => 'xl', 'frequency' => 12400]];

        $this->runCommand();

        $this->assertStringNotContainsString('Short terms', $this->findingsBlock());
        $this->assertSame([], $this->command->queriesAgainst('CHAR_LENGTH'));
    }

    public function testNothingIsSaidWhenTheCatalogueHasNoShortTermAtAll(): void
    {
        $this->serverReports(['innodb_ft_min_token_size' => '3']);

        $this->runCommand();

        $this->assertStringNotContainsString('Short terms', $this->findingsBlock());
    }

    public function testTheDictionaryIsAskedOnlyForTermsBelowTheConfiguredTokenSize(): void
    {
        $this->serverReports(['innodb_ft_min_token_size' => '4']);
        $this->command->shortTerms = [['term' => 'xl', 'frequency' => 12400]];

        $this->runCommand();

        $index = null;

        foreach ($this->command->queries as $position => $sql) {
            if (str_contains($sql, 'SUM(FOUN10FREQUENCY)')) {
                $index = $position;
            }
        }

        $this->assertNotNull($index);
        $this->assertSame([':minimum' => 4], $this->command->queryParameters[$index]);
        $this->assertSame(
            'SELECT FOUN10TERMRAW AS term, SUM(FOUN10FREQUENCY) AS frequency'
            . ' FROM ' . self::DICTIONARY
            . ' WHERE CHAR_LENGTH(FOUN10TERM) < :minimum AND OXSHOPID IN (1)'
            . ' GROUP BY FOUN10TERM ORDER BY frequency DESC LIMIT 5',
            self::collapse($this->command->queries[$index])
        );
    }

    /**
     * One row per shop and language sits in the dictionary, and a list naming
     * the same word four times says less than one naming it once.
     */
    public function testTheTermsAreGroupedAndTheLoudestFiveTaken(): void
    {
        $this->serverReports(['innodb_ft_min_token_size' => '3']);
        $this->command->shortTerms = [['term' => 'xl', 'frequency' => 1]];

        $this->runCommand();

        $sql = $this->command->queriesAgainst('SUM(FOUN10FREQUENCY)')[0];
        $this->assertStringContainsString('GROUP BY FOUN10TERM', $sql);
        $this->assertStringContainsString('ORDER BY frequency DESC', $sql);
        $this->assertStringContainsString('LIMIT 5', $sql);
    }

    public function testEveryShopOfTheRunIsNamedInTheDictionaryQueryOnce(): void
    {
        $this->command->allShopIds = [1, 2];
        $this->activeLanguages = [1 => [0, 1], 2 => [0]];
        $this->serverReports(['innodb_ft_min_token_size' => '3']);
        $this->command->shortTerms = [['term' => 'xl', 'frequency' => 1]];

        $this->runCommand();

        $sql = $this->command->queriesAgainst('SUM(FOUN10FREQUENCY)')[0];
        $this->assertStringContainsString('OXSHOPID IN (1, 2)', $sql);
    }

    // ---------------------------------------------------------------
    // facets counted the slow way
    // ---------------------------------------------------------------

    public function testAnEmptyProductLevelTableBesideAttributeRowsIsAProblem(): void
    {
        $this->command->scopeCounts = [self::ATTRIBUTE => 4200, self::ATTRIBUTE_GROUP => 0];

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringContainsString('[problem] Shop 1, language 0 counts facets the slow way', $text);
        $this->assertStringContainsString(
            'The product level table is empty while attribute rows exist, so every facet count is'
            . ' computed from the variant rows - measured at three to eleven times the cost.'
            . ' A full rebuild derives it: vendor/bin/oe-console foun10:easysearch:reindex',
            $text
        );
    }

    /**
     * One healthy scope must not end the walk - the shop after it may be the
     * one that needs the rebuild.
     */
    public function testTheRemainingScopesAreStillCheckedAfterAHealthyOne(): void
    {
        $this->command->allShopIds = [1, 2];
        $this->activeLanguages = [1 => [0], 2 => [0]];
        $this->tables->existing = [self::INDEX, 'foun10easysearchindex_s2'];
        $this->command->scopeCounts = [
            self::ATTRIBUTE => 4200,
            self::ATTRIBUTE_GROUP => 3100,
            'foun10easysearchindexattribute_s2' => 900,
            'foun10easysearchindexattributegroup_s2' => 0,
        ];

        $this->runCommand();

        $this->assertStringContainsString('Shop 2, language 0 counts facets the slow way', $this->findingsBlock());
    }

    public function testNothingIsSaidWhenTheProductLevelTableIsFilled(): void
    {
        $this->command->scopeCounts = [self::ATTRIBUTE => 4200, self::ATTRIBUTE_GROUP => 3100];

        $this->runCommand();

        $this->assertStringNotContainsString('counts facets the slow way', $this->findingsBlock());
    }

    public function testAShopWithNoAttributesAtAllIsNotAccusedOfAnything(): void
    {
        $this->runCommand();

        $this->assertStringNotContainsString('counts facets the slow way', $this->findingsBlock());
    }

    public function testTheProductLevelTableIsNotEvenAskedAboutWithoutAttributes(): void
    {
        $this->runCommand();

        $asked = array_filter(
            $this->command->scalarQueries,
            static fn (string $sql): bool => str_contains($sql, self::ATTRIBUTE_GROUP)
        );

        $this->assertSame([], $asked);
    }

    // ---------------------------------------------------------------
    // an index older than what it was built from
    // ---------------------------------------------------------------

    public function testAShopWithoutAnIndexTableIsAProblem(): void
    {
        $this->tables->existing = [];

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringContainsString('[problem] Shop 1 has no index at all', $text);
        $this->assertStringContainsString(
            'The module reports itself unavailable and the shop serves its own search - no error,'
            . ' just worse results. Build it: vendor/bin/oe-console foun10:easysearch:reindex',
            $text
        );
    }

    /**
     * The shop with no index is the loud one, and stopping at it would hide
     * everything the shops after it have to say.
     */
    public function testTheRemainingShopsAreStillCheckedAfterOneHasNoIndex(): void
    {
        $this->command->allShopIds = [1, 2];
        $this->activeLanguages = [1 => [0], 2 => [0]];
        $this->tables->existing = ['foun10easysearchindex_s2'];
        $this->command->timestamps = [
            'foun10easysearchindex_s2' => '2026-08-01 10:00:00',
            self::CONFIGURATION => '2026-08-20 09:30:00',
        ];

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringContainsString('Shop 1 has no index at all', $text);
        $this->assertStringContainsString('Shop 2 was configured after it was last indexed', $text);
    }

    public function testAMissingIndexIsNotAlsoComparedForFreshness(): void
    {
        $this->tables->existing = [];

        $this->runCommand();

        $compared = array_filter(
            $this->command->scalarQueries,
            static fn (string $sql): bool => str_contains($sql, 'MAX(OXTIMESTAMP)')
        );

        $this->assertSame([], $compared);
    }

    public function testAnIndexOlderThanItsConfigurationIsAHint(): void
    {
        $this->command->timestamps = [
            self::INDEX => '2026-08-01 10:00:00',
            self::CONFIGURATION => '2026-08-20 09:30:00',
        ];

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringContainsString('[hint] Shop 1 was configured after it was last indexed', $text);
        $this->assertStringContainsString(
            'Attributes changed 2026-08-20 09:30:00, index written 2026-08-01 10:00:00.'
            . ' Which attributes are facets is decided while documents are written,'
            . ' so the sidebar shows the old set until a rebuild.',
            $text
        );
    }

    public function testTheRemainingShopsAreStillCheckedAfterAFreshOne(): void
    {
        $this->command->allShopIds = [1, 2];
        $this->activeLanguages = [1 => [0], 2 => [0]];
        $this->tables->existing = [self::INDEX, 'foun10easysearchindex_s2'];
        $this->command->timestamps = [
            self::INDEX => '2026-08-30 08:00:00',
            'foun10easysearchindex_s2' => '2026-08-01 10:00:00',
            self::CONFIGURATION => '2026-08-20 09:30:00',
        ];

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringNotContainsString('Shop 1 was configured after', $text);
        $this->assertStringContainsString('Shop 2 was configured after it was last indexed', $text);
    }

    public function testNothingIsSaidWhenTheIndexIsNewerThanTheConfiguration(): void
    {
        $this->command->timestamps = [
            self::INDEX => '2026-08-20 09:30:00',
            self::CONFIGURATION => '2026-08-01 10:00:00',
        ];

        $this->runCommand();

        $this->assertStringNotContainsString('was configured after', $this->findingsBlock());
    }

    public function testAnIndexWrittenAtTheSameMomentIsNotStale(): void
    {
        $this->command->timestamps = [
            self::INDEX => '2026-08-20 09:30:00',
            self::CONFIGURATION => '2026-08-20 09:30:00',
        ];

        $this->runCommand();

        $this->assertStringNotContainsString('was configured after', $this->findingsBlock());
    }

    public function testNothingIsSaidWhenTheConfigurationHasNoTimestampYet(): void
    {
        $this->command->timestamps = [self::INDEX => '2026-08-01 10:00:00'];

        $this->runCommand();

        $this->assertStringNotContainsString('was configured after', $this->findingsBlock());
    }

    public function testNothingIsSaidWhenTheIndexHasNoTimestampYet(): void
    {
        $this->command->timestamps = [self::CONFIGURATION => '2026-08-20 09:30:00'];

        $this->runCommand();

        $this->assertStringNotContainsString('was configured after', $this->findingsBlock());
    }

    /**
     * The configuration timestamp comes from the settings table, not from the
     * index copy of it - the point of the comparison is that the two differ.
     */
    public function testTheConfigurationTimestampIsReadFromTheSettingsTable(): void
    {
        $this->runCommand();

        $configured = array_values(array_filter(
            $this->command->scalarQueries,
            static fn (string $sql): bool => str_contains($sql, 'FROM ' . self::CONFIGURATION . ' ')
        ));

        $this->assertCount(1, $configured);
        $this->assertStringContainsString('SELECT MAX(OXTIMESTAMP)', $configured[0]);
        $this->assertStringContainsString('WHERE OXSHOPID = 1', $configured[0]);
    }

    public function testTheIndexTimestampIsReadForTheLanguageOfTheScope(): void
    {
        $this->activeLanguages = [1 => [2]];

        $this->runCommand();

        $indexed = array_values(array_filter(
            $this->command->scalarQueries,
            static fn (string $sql): bool => str_contains($sql, 'FROM ' . self::INDEX . ' ')
        ));

        $this->assertCount(1, $indexed);
        $this->assertSame(
            'SELECT MAX(OXTIMESTAMP) FROM ' . self::INDEX . ' WHERE OXSHOPID = 1 AND FOUN10LANGID = 2',
            $indexed[0]
        );
    }

    // ---------------------------------------------------------------
    // tables a rebuild left behind
    // ---------------------------------------------------------------

    public function testAShadowTableLeftBehindIsAHint(): void
    {
        $this->weigh(self::INDEX . '_tmp', 12 * self::MEGABYTE);

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertStringContainsString('[hint] A rebuild left tables behind', $text);
        $this->assertStringContainsString(
            self::INDEX . '_tmp. A _tmp or _old table means a rebuild died before its swap.'
            . ' The next full rebuild clears them; nothing reads them meanwhile.',
            $text
        );
    }

    public function testARetiredTableLeftBehindIsAHint(): void
    {
        $this->weigh(self::CATEGORY . '_old', 3 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString(self::CATEGORY . '_old', $this->findingsBlock());
    }

    public function testEveryLeftoverIsNamedInOneFinding(): void
    {
        $this->weigh(self::INDEX . '_tmp', 1);
        $this->weigh(self::INDEX . '_old', 1);
        $this->weigh(self::DICTIONARY . '_tmp', 1);

        $this->runCommand();

        $text = $this->findingsBlock();
        $this->assertSame(1, substr_count($text, 'A rebuild left tables behind'));
        $this->assertStringContainsString(self::INDEX . '_tmp, ' . self::INDEX . '_old', $text);
        $this->assertStringContainsString(self::DICTIONARY . '_tmp', $text);
    }

    public function testNothingIsSaidWhenNoRebuildLeftAnythingBehind(): void
    {
        $this->weigh(self::INDEX, 300 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringNotContainsString('left tables behind', $this->findingsBlock());
    }

    /**
     * A leftover weighs nothing towards the pool advice: it is not read, and
     * counting it would inflate the very number the advice is derived from.
     */
    public function testALeftoverDoesNotCountTowardsTheFootprint(): void
    {
        $this->weigh(self::INDEX, 300 * self::MEGABYTE);
        $this->weigh(self::INDEX . '_old', 300 * self::MEGABYTE);

        $this->runCommand();

        $this->assertStringContainsString('Search tables together: 300 MB', $this->displayText());
    }

    // ---------------------------------------------------------------
    // the timed search
    // ---------------------------------------------------------------

    private function engineAnswers(int $totalCount = 0, int $facets = 0): SpySearchEngine
    {
        $engine = new SpySearchEngine([], $totalCount);

        for ($i = 0; $i < $facets; $i++) {
            $engine->facets[] = new Facet('attr-' . $i, 'Facet ' . $i, []);
        }

        $this->command->engine = $engine;

        return $engine;
    }

    public function testNoTimingSkipsTheSearchEntirely(): void
    {
        $this->engineAnswers();

        $this->runCommand(['--no-timing' => true]);

        $this->assertSame(0, $this->command->engineLookups);
    }

    public function testTheNamedTermIsWhatGetsSearchedFor(): void
    {
        $engine = $this->engineAnswers();
        $this->command->busiestTerm = 'ignored';

        $this->runCommand(['--no-timing' => false, '--term' => 'winterjacke']);

        $this->assertSame('winterjacke', $engine->searches[0]->getTerm());
        $this->assertStringContainsString('One search for "winterjacke"', $this->displayText());
    }

    /**
     * A timing is only worth reading if the term is one the shop would really
     * be asked for, so with none named the catalogue names its own.
     */
    public function testTheBusiestIndexedTermIsUsedWhenNoneWasNamed(): void
    {
        $engine = $this->engineAnswers();
        $this->command->busiestTerm = 'hemd';

        $this->runCommand(['--no-timing' => false]);

        $this->assertSame('hemd', $engine->searches[0]->getTerm());
    }

    public function testTheBusiestTermIsTakenFromTheScopeThatIsTimed(): void
    {
        $this->activeLanguages = [1 => [2]];
        $this->engineAnswers();
        $this->command->busiestTerm = 'hemd';

        $this->runCommand(['--no-timing' => false]);

        $sql = $this->command->queriesAgainst('ORDER BY FOUN10FREQUENCY DESC')[0];
        $index = array_search($sql, $this->command->queries, true);

        $this->assertSame(
            'SELECT FOUN10TERMRAW AS term'
            . ' FROM ' . self::DICTIONARY
            . ' WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId'
            . ' ORDER BY FOUN10FREQUENCY DESC LIMIT 1',
            self::collapse($sql)
        );
        $this->assertSame([':shopId' => 1, ':langId' => 2], $this->command->queryParameters[$index]);
    }

    public function testAnEmptyDictionaryLeavesTheSearchUnrunAndSaysWhy(): void
    {
        $this->engineAnswers();

        $this->runCommand(['--no-timing' => false]);

        $this->assertSame(0, $this->command->engineLookups);
        $this->assertStringContainsString('Pass --term to name one.', $this->displayText());
    }

    public function testAnEmptyTermIsTreatedAsNoTerm(): void
    {
        $this->engineAnswers();
        $this->command->busiestTerm = 'hemd';

        $this->runCommand(['--no-timing' => false, '--term' => '']);

        $this->assertSame(0, $this->command->engineLookups);
    }

    /**
     * The first search of a process pays for caches every later one finds warm,
     * so it is run and thrown away.
     */
    public function testTheFirstSearchIsRunButNotMeasured(): void
    {
        $engine = $this->engineAnswers();
        $this->command->clock = [1000.0, 1000.1];

        $this->runCommand(['--no-timing' => false, '--term' => 'hemd']);

        $this->assertSame(2, $engine->searchCount());
        $this->assertStringContainsString('100 ms', $this->displayText());
    }

    public function testTheHitCountAndTheFacetCountAreReported(): void
    {
        $this->engineAnswers(18234, 6);
        $this->command->clock = [1000.0, 1000.05];

        $this->runCommand(['--no-timing' => false, '--term' => 'hemd']);

        $this->assertStringContainsString('18,234 hits, 6 facets, 50 ms', $this->displayText());
    }

    public function testExactlyOneSecondIsAlreadyReportedInSeconds(): void
    {
        $this->engineAnswers();
        $this->command->clock = [1000.0, 1001.0];

        $this->runCommand(['--no-timing' => false, '--term' => 'hemd']);

        $this->assertStringContainsString('1.00 s', $this->displayText());
    }

    public function testASearchOverASecondIsReportedInSeconds(): void
    {
        $this->engineAnswers();
        $this->command->clock = [1000.0, 1009.0];

        $this->runCommand(['--no-timing' => false, '--term' => 'hemd']);

        $this->assertStringContainsString('9.00 s', $this->displayText());
    }

    public function testAFastSearchIsWorthNoFindingAtAll(): void
    {
        $this->engineAnswers();
        $this->command->clock = [1000.0, 1000.4];

        $this->runCommand(['--no-timing' => false, '--term' => 'hemd']);

        $this->assertStringNotContainsString('takes longer than it should', $this->findingsBlock());
    }

    public function testHalfASecondIsAlreadyWorthAHint(): void
    {
        $this->engineAnswers();
        $this->command->clock = [1000.0, 1000.5];

        $this->runCommand(['--no-timing' => false, '--term' => 'hemd']);

        $text = $this->findingsBlock();
        $this->assertStringContainsString('[hint] A search takes longer than it should', $text);
        $this->assertStringContainsString(
            '500 ms for "hemd". On a warm server a catalogue of this size answers'
            . ' in a few hundred milliseconds. Check the buffer pool first - it is what this usually is.',
            $text
        );
    }

    public function testTwoSecondsIsStillOnlyAHint(): void
    {
        $this->engineAnswers();
        $this->command->clock = [1000.0, 1002.0];

        $this->runCommand(['--no-timing' => false, '--term' => 'hemd']);

        $this->assertStringContainsString('[hint] A search takes longer', $this->findingsBlock());
    }

    public function testMoreThanTwoSecondsIsAProblem(): void
    {
        $this->engineAnswers();
        $this->command->clock = [1000.0, 1002.5];

        $this->runCommand(['--no-timing' => false, '--term' => 'hemd']);

        $this->assertStringContainsString('[problem] A search takes longer', $this->findingsBlock());
    }

    public function testAnEngineThatCannotBeReachedIsAWarningRatherThanACrash(): void
    {
        $this->command->engine = null;

        $this->assertSame(Command::SUCCESS, $this->runCommand(['--no-timing' => false, '--term' => 'hemd']));
        $this->assertStringContainsString('Could not run a search: no search engine is registered', $this->displayText());
    }

    public function testAnEngineThatFailsMidSearchIsAWarningRatherThanACrash(): void
    {
        $engine = $this->engineAnswers();
        $engine->failing = true;

        $this->assertSame(Command::SUCCESS, $this->runCommand(['--no-timing' => false, '--term' => 'hemd']));
        $this->assertStringContainsString('Could not run a search: the engine is not answering', $this->displayText());
    }

    public function testAFailedSearchProducesNoTimingFinding(): void
    {
        $engine = $this->engineAnswers();
        $engine->failing = true;

        $this->runCommand(['--no-timing' => false, '--term' => 'hemd']);

        $this->assertStringNotContainsString('takes longer than it should', $this->findingsBlock());
    }

    public function testOnlyTheFirstScopeIsTimed(): void
    {
        $this->command->allShopIds = [1, 2];
        $this->activeLanguages = [1 => [0], 2 => [0]];
        $engine = $this->engineAnswers();

        $this->runCommand(['--no-timing' => false, '--term' => 'hemd']);

        $this->assertSame(2, $engine->searchCount());
        $this->assertSame(1, $engine->searches[0]->getShopId());
    }

    public function testARunWithNoScopeAtAllTimesNothing(): void
    {
        $this->activeLanguages = [];
        $this->engineAnswers();
        $this->command->busiestTerm = 'hemd';

        $this->runCommand(['--no-timing' => false]);

        $this->assertSame(0, $this->command->engineLookups);
    }

    // ---------------------------------------------------------------
    // what the report ends with
    // ---------------------------------------------------------------

    public function testAHealthyInstallationIsToldSoInOneLine(): void
    {
        $this->serverReports();

        $this->runCommand();

        $this->assertStringContainsString('Nothing to report.', $this->findingsBlock());
    }

    /**
     * What is worth reading first comes first: the findings are collected in
     * the order the checks run, not in the order they matter.
     */
    public function testProblemsComeBeforeHintsAndHintsBeforeWhatIsFine(): void
    {
        $this->serverReports([
            'innodb_buffer_pool_size' => (string) (2 * self::GIGABYTE),
            'max_allowed_packet' => (string) (4 * self::MEGABYTE),
        ]);
        $this->weigh(self::INDEX, 300 * self::MEGABYTE);
        $this->command->scopeCounts = [self::ATTRIBUTE => 4200, self::ATTRIBUTE_GROUP => 0];

        $this->runCommand();

        $text = $this->findingsBlock();
        $problem = strpos($text, '[problem]');
        $hint = strpos($text, '[hint]');
        $ok = strpos($text, '[ok]');

        $this->assertIsInt($problem);
        $this->assertIsInt($hint);
        $this->assertIsInt($ok);
        $this->assertLessThan($hint, $problem);
        $this->assertLessThan($ok, $hint);
    }

    /**
     * A blank line between findings. Without it the details run into the next
     * headline and the list stops being scannable.
     */
    public function testFindingsAreSeparatedByABlankLine(): void
    {
        $this->serverReports([
            'max_allowed_packet' => (string) (4 * self::MEGABYTE),
            'innodb_buffer_pool_size' => (string) (2 * self::GIGABYTE),
        ]);
        $this->weigh(self::INDEX, 300 * self::MEGABYTE);

        $this->runCommand();

        $this->assertMatchesRegularExpression('/\n\n\s+\[ok\]/', $this->display());
    }

    /**
     * Severity is carried by the colour as well as the word, so the three are
     * rendered in three different styles rather than one.
     */
    public function testEachSeverityIsRenderedInItsOwnStyle(): void
    {
        $this->serverReports([
            'max_allowed_packet' => (string) (4 * self::MEGABYTE),
            'innodb_buffer_pool_size' => (string) (2 * self::GIGABYTE),
        ]);
        $this->weigh(self::INDEX, 300 * self::MEGABYTE);
        $this->command->scopeCounts = [self::ATTRIBUTE => 4200, self::ATTRIBUTE_GROUP => 0];

        $this->tester->execute(['--no-timing' => true], ['decorated' => true]);

        $problem = $this->styleOf('problem');
        $hint = $this->styleOf('hint');
        $ok = $this->styleOf('ok');

        $this->assertNotSame('', $problem);
        $this->assertNotSame($problem, $hint);
        $this->assertNotSame($hint, $ok);
        $this->assertNotSame($problem, $ok);
    }

    /**
     * The escape sequence a severity marker is printed with, whatever it is -
     * the point is that the three differ, not which colour each one got.
     */
    private function styleOf(string $severity): string
    {
        $matched = preg_match(
            '/\e\[([0-9;]+)m\[' . $severity . '\]/',
            $this->tester->getDisplay(true),
            $matches
        );

        return $matched === 1 ? $matches[1] : '';
    }

    // ---------------------------------------------------------------
    // the exit code
    // ---------------------------------------------------------------

    public function testARunThatFoundSomethingStillExitsZeroByDefault(): void
    {
        $this->tables->existing = [];

        $this->assertSame(Command::SUCCESS, $this->runCommand());
        $this->assertStringContainsString('[problem]', $this->findingsBlock());
    }

    public function testStrictFailsWhenSomethingNeedsDoing(): void
    {
        $this->tables->existing = [];

        $this->assertSame(Command::FAILURE, $this->runCommand(['--strict' => true]));
    }

    public function testStrictSucceedsWhenNothingDoes(): void
    {
        $this->serverReports();

        $this->assertSame(Command::SUCCESS, $this->runCommand(['--strict' => true]));
    }

    /**
     * A hint is not a problem: a build that fails on every piece of advice is
     * one nobody leaves switched on.
     */
    public function testStrictSucceedsWhenAllThatWasFoundWereHints(): void
    {
        $this->serverReports(['max_allowed_packet' => (string) (4 * self::MEGABYTE)]);

        $this->assertSame(Command::SUCCESS, $this->runCommand(['--strict' => true]));
        $this->assertStringContainsString('[hint]', $this->findingsBlock());
    }

    // ---------------------------------------------------------------
    // an installation that is already broken
    // ---------------------------------------------------------------

    /**
     * This command exists to describe a broken installation, so a database that
     * refuses every question has to produce a report rather than a stack trace.
     */
    public function testADatabaseThatAnswersNothingStillProducesAReport(): void
    {
        $this->command->databaseFails = true;

        $this->assertSame(Command::SUCCESS, $this->runCommand());

        $text = $this->displayText();
        $this->assertStringContainsString('version unknown', $text);
        $this->assertStringContainsString('Search tables together: -', $text);
    }

    public function testAnUnreadableTableIsReportedAsMissingRatherThanCrashing(): void
    {
        $this->command->databaseFails = true;

        $this->runCommand();

        $this->assertStringContainsString(self::INDEX . ' missing', $this->displayText());
    }

    public function testAScalarTheDatabaseRefusesReadsAsEmptyRatherThanCrashing(): void
    {
        $this->command->databaseFails = true;

        $this->runCommand();

        // Empty timestamps mean "cannot tell", and cannot-tell is not a finding.
        $this->assertStringNotContainsString('was configured after', $this->findingsBlock());
    }
}
