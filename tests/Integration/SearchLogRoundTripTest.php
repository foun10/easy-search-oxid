<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Integration;

use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Result\SearchResult;
use foun10\EasySearch\Log\Period;
use foun10\EasySearch\Log\SearchLog;
use foun10\EasySearch\Log\SearchLogger;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use PHPUnit\Framework\TestCase;

/**
 * What customers searched for, written and read back.
 *
 * The report screen is only as good as what the logger stored, and the two
 * halves are written against the same table from opposite ends - so they are
 * worth checking together rather than each against a fixture.
 *
 * The interesting property is that the log **counts** rather than records: one
 * row per term, shop, language and day, incremented. A shop doing thousands of
 * searches a day must not grow a row per search, and the zero-hit report only
 * means anything if the hit count on that row tracks the newest search rather
 * than the first.
 *
 * Written against a shop id no installation uses and cleared afterwards.
 */
class SearchLogRoundTripTest extends TestCase
{
    private const SCRATCH_SHOP_ID = 990;
    private const LANG_ID = 0;

    private SearchLog $log;

    protected function setUp(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();

        if (!$container->get(ModuleSettings::class)->isSearchLogEnabled()) {
            $this->markTestSkipped(
                'Search logging is switched off for this shop - enable '
                . 'FOUN10EASYSEARCH_LOG_ENABLED to cover the log.'
            );
        }

        $this->log = $container->get(SearchLog::class);

        $this->removeScratchRows();
    }

    protected function tearDown(): void
    {
        $this->removeScratchRows();
    }

    private function removeScratchRows(): void
    {
        DatabaseProvider::getDb()->execute(
            'DELETE FROM ' . SearchLog::TABLE . ' WHERE OXSHOPID = :shopId',
            [':shopId' => self::SCRATCH_SHOP_ID]
        );
    }

    /**
     * One search, from a fresh request.
     *
     * The logger keeps a per-request note of what it already counted, because
     * a single page load runs the same search more than once - the Search model
     * and the result provider both ask - and counting that twice would double
     * every number on the report. Production therefore gets one logger per
     * request, and a test simulating several requests has to do the same. The
     * guard itself is covered below.
     *
     * @param array<int, string> $productIds
     */
    private function logSearch(string $term, array $productIds = ['a-1'], ?int $total = null): void
    {
        $this->newRequestLogger()->log(
            new SearchQuery($term, self::SCRATCH_SHOP_ID, self::LANG_ID),
            new SearchResult($productIds, $total ?? count($productIds))
        );
    }

    private function newRequestLogger(): SearchLogger
    {
        $container = ContainerFactory::getInstance()->getContainer();

        return new SearchLogger(
            $container->get(\foun10\EasySearch\Correction\Normalizer::class),
            $container->get(ModuleSettings::class),
            $container->get(\foun10\EasySearch\Log\TermFilter::class)
        );
    }

    private function period(): Period
    {
        return Period::named(Period::MONTH);
    }

    // ---------------------------------------------------------------
    // counting rather than recording
    // ---------------------------------------------------------------

    public function testASearchIsCounted(): void
    {
        $this->logSearch('winterjacke');

        $summary = $this->log->getSummary(self::SCRATCH_SHOP_ID, self::LANG_ID, $this->period());

        $this->assertSame(1, (int) $summary['searches']);
        $this->assertSame(1, (int) $summary['terms']);
    }

    /**
     * A shop doing thousands of searches a day must not grow a row per search.
     */
    public function testTheSameTermTwiceIsOneRowCountedTwice(): void
    {
        $this->logSearch('winterjacke');
        $this->logSearch('winterjacke');
        $this->logSearch('winterjacke');

        $summary = $this->log->getSummary(self::SCRATCH_SHOP_ID, self::LANG_ID, $this->period());

        $this->assertSame(3, (int) $summary['searches']);
        $this->assertSame(1, (int) $summary['terms'], 'The same term must not become three rows.');
        $this->assertSame(1, $this->rowCount());
    }

    /**
     * One page load asks for the same search more than once - the Search model
     * runs it and the result provider runs it again - so the logger counts a
     * term once per request. Without that, every number on the report is
     * inflated by a factor nobody can see, and the top-terms list becomes a
     * ranking of which code path happens to run twice.
     */
    public function testTheSameTermTwiceInOneRequestIsCountedOnce(): void
    {
        $logger = $this->newRequestLogger();
        $query = new SearchQuery('winterjacke', self::SCRATCH_SHOP_ID, self::LANG_ID);

        $logger->log($query, new SearchResult(['a-1'], 1));
        $logger->log($query, new SearchResult(['a-1'], 1));

        $this->assertSame(
            1,
            (int) $this->log->getSummary(self::SCRATCH_SHOP_ID, self::LANG_ID, $this->period())['searches']
        );
    }

    /**
     * The guard is per term, not per request - two different searches in one
     * request are two searches.
     */
    public function testTwoDifferentTermsInOneRequestAreBothCounted(): void
    {
        $logger = $this->newRequestLogger();

        $logger->log(
            new SearchQuery('winterjacke', self::SCRATCH_SHOP_ID, self::LANG_ID),
            new SearchResult(['a-1'], 1)
        );
        $logger->log(
            new SearchQuery('sommerkleid', self::SCRATCH_SHOP_ID, self::LANG_ID),
            new SearchResult(['a-2'], 1)
        );

        $this->assertSame(2, $this->rowCount());
    }

    public function testTwoDifferentTermsAreTwoRows(): void
    {
        $this->logSearch('winterjacke');
        $this->logSearch('sommerkleid');

        $this->assertSame(2, $this->rowCount());
        $this->assertSame(
            2,
            (int) $this->log->getSummary(self::SCRATCH_SHOP_ID, self::LANG_ID, $this->period())['terms']
        );
    }

    /**
     * Terms that differ only in case or spacing are the same search as far as
     * a merchant reading the report is concerned.
     */
    public function testTermsAreCountedOnTheirNormalisedForm(): void
    {
        $this->logSearch('Winterjacke');
        $this->logSearch('  winterjacke ');

        $this->assertSame(1, $this->rowCount());
    }

    // ---------------------------------------------------------------
    // what the report is read for
    // ---------------------------------------------------------------

    public function testATermThatFoundNothingIsOnTheZeroHitList(): void
    {
        $this->logSearch('gibtesnicht', [], 0);
        $this->logSearch('winterjacke', ['a-1'], 1);

        $zero = $this->log->getZeroHitTerms(self::SCRATCH_SHOP_ID, self::LANG_ID, $this->period(), 10);

        $this->assertSame(['gibtesnicht'], array_column($zero, 'term'));
    }

    public function testATermThatFoundSomethingIsNotOnIt(): void
    {
        $this->logSearch('winterjacke', ['a-1', 'a-2'], 2);

        $this->assertSame(
            [],
            $this->log->getZeroHitTerms(self::SCRATCH_SHOP_ID, self::LANG_ID, $this->period(), 10)
        );
    }

    /**
     * The stored hit count is what the *last* search for the term found, which
     * is why a term drops off the zero-hit list once a synonym rule makes it
     * find something - and why the report screen has to say so.
     */
    public function testTheHitCountTracksTheNewestSearchRatherThanTheFirst(): void
    {
        $this->logSearch('bralette', [], 0);
        $this->assertCount(1, $this->log->getZeroHitTerms(self::SCRATCH_SHOP_ID, self::LANG_ID, $this->period(), 10));

        $this->logSearch('bralette', ['a-1'], 7);

        $this->assertSame(
            [],
            $this->log->getZeroHitTerms(self::SCRATCH_SHOP_ID, self::LANG_ID, $this->period(), 10),
            'A term that now finds something must leave the zero-hit list.'
        );
    }

    public function testTheTopListIsOrderedByHowOftenSomethingWasSearched(): void
    {
        $this->logSearch('selten');
        foreach (range(1, 3) as $ignored) {
            $this->logSearch('haeufig');
        }

        $top = $this->log->getTopTerms(self::SCRATCH_SHOP_ID, self::LANG_ID, $this->period(), 10);

        $this->assertSame(['haeufig', 'selten'], array_column($top, 'term'));
    }

    public function testTheChartCountsTheSameSearchesAsTheSummary(): void
    {
        $this->logSearch('winterjacke');
        $this->logSearch('winterjacke');
        $this->logSearch('gibtesnicht', [], 0);

        $series = $this->log->getSeries(self::SCRATCH_SHOP_ID, self::LANG_ID, $this->period());
        $searches = 0;

        foreach ($series as $point) {
            $searches += (int) $point['searches'];
        }

        $this->assertSame(3, $searches);
    }

    // ---------------------------------------------------------------
    // what is deliberately not logged
    // ---------------------------------------------------------------

    /**
     * A category or manufacturer listing is not a search, and the module reads
     * all three through the same query object.
     */
    public function testAListingWithNoTermIsNotASearch(): void
    {
        $this->logSearch('   ');

        $this->assertSame(0, $this->rowCount());
    }

    /**
     * Narrowing a result the customer is already looking at is not a new
     * search - counting it would report every facet click as demand for the
     * term the customer typed once.
     */
    public function testNarrowingAResultIsNotANewSearch(): void
    {
        $this->newRequestLogger()->log(
            new SearchQuery(
                'winterjacke',
                self::SCRATCH_SHOP_ID,
                self::LANG_ID,
                [new \foun10\EasySearch\Engine\Query\FacetFilter('attr-1', ['v-1'])]
            ),
            new SearchResult(['a-1'], 1)
        );

        $this->assertSame(0, $this->rowCount());
    }

    public function testPageTwoIsNotANewSearch(): void
    {
        $this->newRequestLogger()->log(
            new SearchQuery(
                'winterjacke',
                self::SCRATCH_SHOP_ID,
                self::LANG_ID,
                [],
                SearchQuery::SORT_RELEVANCE,
                24
            ),
            new SearchResult(['a-1'], 1)
        );

        $this->assertSame(0, $this->rowCount());
    }

    /**
     * A scanner's payload is not a customer telling you what they wanted, and
     * the report it would land in is read by people.
     */
    public function testAScannerPayloadIsNotStoredAtAll(): void
    {
        $this->logSearch('<script>alert(1)</script>');
        $this->logSearch('../../../../etc/passwd');

        $this->assertSame(0, $this->rowCount());
    }

    /**
     * Never throws: a failure to count must not cost the customer their search
     * result. A term far past the column width is the cheapest way to ask.
     */
    public function testAnAbsurdlyLongTermDoesNotBreakTheSearch(): void
    {
        $this->logSearch(str_repeat('a', 5000));

        $this->assertLessThanOrEqual(1, $this->rowCount());
    }

    /**
     * Nothing about a person is stored, which is what lets the report exist at
     * all without a data-protection conversation.
     */
    public function testTheLogHoldsNoColumnThatCouldIdentifyAnybody(): void
    {
        $columns = array_column(
            DatabaseHelper::fetchAll('SHOW COLUMNS FROM ' . SearchLog::TABLE),
            'Field'
        );

        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/(USER|IP|SESSION|AGENT|EMAIL)/i',
                $column,
                'The search log must not carry anything that points at a person.'
            );
        }
    }

    private function rowCount(): int
    {
        $rows = DatabaseHelper::fetchAll(
            'SELECT COUNT(*) AS n FROM ' . SearchLog::TABLE . ' WHERE OXSHOPID = :shopId',
            [':shopId' => self::SCRATCH_SHOP_ID]
        );

        return (int) $rows[0]['n'];
    }
}
