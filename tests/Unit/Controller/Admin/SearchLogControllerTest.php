<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Controller\Admin;

use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Core\SynonymConfiguration;
use foun10\EasySearch\Correction\Normalizer;
use foun10\EasySearch\Log\Period;
use foun10\EasySearch\Log\SearchLog;
use foun10\EasySearch\Log\TermFilter;
use foun10\EasySearch\Synonym\SynonymRule;
use foun10\EasySearch\Tests\Unit\Double\TestableSearchLogController;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The search report.
 *
 * A read-only screen, which makes it sound simpler than it is. Everything on it
 * is derived: a share, a set of bar heights that only make sense relative to
 * the tallest one, and two lists that are read longer than they are shown
 * because rows get dropped after the query.
 *
 * That last part is where the arithmetic earns its tests. Junk rows are removed
 * *after* the database has answered - a filter the writer applies today, but
 * not to rows written before it existed - so the query over-reads and the list
 * is cut to length afterwards. Getting that wrong gives a top ten with four
 * rows in it and no hint why.
 *
 * The zero-hit list is the point of the screen: every row is a customer who
 * wanted something and left without it. Each row says whether a synonym rule
 * already covers it, because a rule does not remove the row - the stored hit
 * count is what the *last* search found - and without that flag the rule reads
 * as not having worked.
 */
class SearchLogControllerTest extends TestCase
{
    private TestableSearchLogController $controller;

    /** @var array<string, int> */
    private array $summary = ['searches' => 0, 'terms' => 0, 'zeroSearches' => 0, 'zeroTerms' => 0];

    /** @var array<int, array<string, mixed>> */
    private array $series = [];

    /** @var array<int, array<string, mixed>> */
    private array $topTerms = [];

    /** @var array<int, array<string, mixed>> */
    private array $zeroHitTerms = [];

    /** @var array<int, array{shopId: int, langId: int, period: Period, limit: int}> */
    private array $topCalls = [];

    private bool $logFails = false;

    /** @var string[] Terms the filter refuses, mapped to the reason */
    private array $suspicious = [];

    /** @var SynonymRule[] */
    private array $rules = [];

    /** @var array<int, array<int, array{id: int, abbr: string, name: string}>> Keyed by shop id */
    private array $languages = [1 => [['id' => 0, 'abbr' => 'de', 'name' => 'Deutsch']]];

    protected function setUp(): void
    {
        $log = $this->createMock(SearchLog::class);
        $log->method('getSummary')->willReturnCallback(
            fn (): array => $this->answer($this->summary)
        );
        $log->method('getSeries')->willReturnCallback(
            fn (): array => $this->answer($this->series)
        );
        $log->method('getTopTerms')->willReturnCallback(
            function (int $shopId, int $langId, Period $period, int $limit): array {
                $this->topCalls[] = [
                    'shopId' => $shopId,
                    'langId' => $langId,
                    'period' => $period,
                    'limit' => $limit,
                ];

                return $this->answer($this->topTerms);
            }
        );
        $log->method('getZeroHitTerms')->willReturnCallback(
            fn (): array => $this->answer($this->zeroHitTerms)
        );

        $filter = $this->createMock(TermFilter::class);
        $filter->method('check')->willReturnCallback(
            fn (string $term): ?string => $this->suspicious[$term] ?? null
        );

        $normalizer = $this->createMock(Normalizer::class);
        $normalizer->method('normalize')->willReturnCallback(
            static fn (string $term): string => mb_strtolower(trim($term))
        );

        $synonyms = $this->createMock(SynonymConfiguration::class);
        $synonyms->method('getActiveRules')->willReturnCallback(fn (): array => $this->rules);

        $shopLanguages = $this->createMock(ShopLanguages::class);
        $shopLanguages->method('getActive')->willReturnCallback(
            fn (?int $shopId = null): array => $this->languages[$shopId] ?? []
        );

        $this->controller = new TestableSearchLogController();
        $this->controller->services = [
            SearchLog::class => $log,
            TermFilter::class => $filter,
            Normalizer::class => $normalizer,
            SynonymConfiguration::class => $synonyms,
            ShopLanguages::class => $shopLanguages,
        ];
    }

    /**
     * @param array<mixed> $rows
     *
     * @return array<mixed>
     */
    private function answer(array $rows): array
    {
        if ($this->logFails) {
            throw new RuntimeException('Table foun10easysearchlog does not exist');
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function withRequest(array $parameters): void
    {
        $this->controller->request->escaped = $parameters;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function terms(int $count, int $searches = 10): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['term' => 'term-' . $i, 'searches' => $searches, 'hits' => 5];
        }

        return $rows;
    }

    // ---------------------------------------------------------------
    // the summary
    // ---------------------------------------------------------------

    public function testTheSummaryCarriesWhatTheLogCounted(): void
    {
        $this->summary = ['searches' => 1200, 'terms' => 340, 'zeroSearches' => 180, 'zeroTerms' => 90];

        $summary = $this->controller->getSummary();

        $this->assertSame(1200, $summary['searches']);
        $this->assertSame(340, $summary['terms']);
        $this->assertSame(180, $summary['zeroSearches']);
        $this->assertSame(90, $summary['zeroTerms']);
    }

    /**
     * The share is the number the screen is read for - how much of the traffic
     * left with nothing.
     */
    public function testTheZeroShareIsWorkedOutFromTheTwoCounts(): void
    {
        $this->summary = ['searches' => 1200, 'terms' => 0, 'zeroSearches' => 180, 'zeroTerms' => 0];

        $this->assertSame(15.0, $this->controller->getSummary()['zeroShare']);
    }

    public function testTheShareIsRoundedToOneDecimal(): void
    {
        $this->summary = ['searches' => 3, 'terms' => 0, 'zeroSearches' => 1, 'zeroTerms' => 0];

        $this->assertSame(33.3, $this->controller->getSummary()['zeroShare']);
    }

    /**
     * A shop with no searches has no share, and dividing by the count would
     * take the screen down rather than say so.
     */
    public function testAPeriodWithNoSearchesHasNoShareRatherThanAnError(): void
    {
        $this->summary = ['searches' => 0, 'terms' => 0, 'zeroSearches' => 0, 'zeroTerms' => 0];

        $this->assertSame(0.0, $this->controller->getSummary()['zeroShare']);
    }

    /**
     * The template asks for the summary and hasData() asks again; the log is
     * read once for both.
     */
    public function testTheSummaryIsReadOnceHoweverOftenItIsAskedFor(): void
    {
        $this->summary = ['searches' => 5, 'terms' => 0, 'zeroSearches' => 0, 'zeroTerms' => 0];

        $this->controller->getSummary();
        $this->logFails = true;

        $this->assertSame(5, $this->controller->getSummary()['searches']);
        $this->assertTrue($this->controller->hasData());
    }

    public function testAShopThatHasLoggedNothingIsSaidToHaveNoData(): void
    {
        $this->assertFalse($this->controller->hasData());
    }

    // ---------------------------------------------------------------
    // the chart
    // ---------------------------------------------------------------

    /**
     * Heights are worked out here rather than in the template because the
     * scale is a property of the whole series - a bar cannot know how tall it
     * should be on its own.
     */
    public function testBarsAreScaledAgainstTheTallestOfThem(): void
    {
        $this->series = [
            ['bucket' => '2026-08-01', 'searches' => 100, 'zeroSearches' => 0, 'inPeriod' => true],
            ['bucket' => '2026-08-02', 'searches' => 50, 'zeroSearches' => 0, 'inPeriod' => true],
        ];

        $chart = $this->controller->getChart();

        $this->assertSame(100.0, $chart[0]['height']);
        $this->assertSame(50.0, $chart[1]['height']);
    }

    /**
     * A day with searches always draws something, so an empty bar means no
     * searches rather than very few.
     */
    public function testADayWithVeryFewSearchesStillDrawsABar(): void
    {
        $this->series = [
            ['bucket' => '2026-08-01', 'searches' => 10000, 'zeroSearches' => 0, 'inPeriod' => true],
            ['bucket' => '2026-08-02', 'searches' => 1, 'zeroSearches' => 0, 'inPeriod' => true],
        ];

        $this->assertSame(2.0, $this->controller->getChart()[1]['height']);
    }

    public function testADayWithNoSearchesDrawsNothing(): void
    {
        $this->series = [['bucket' => '2026-08-01', 'searches' => 0, 'zeroSearches' => 0, 'inPeriod' => true]];

        $chart = $this->controller->getChart();

        $this->assertSame(0.0, $chart[0]['height']);
        $this->assertSame(0.0, $chart[0]['zeroHeight']);
    }

    /**
     * The zero share of a bar is drawn inside it, so it scales against that
     * bar rather than against the chart.
     */
    public function testTheZeroPartOfABarIsScaledAgainstThatBar(): void
    {
        $this->series = [
            ['bucket' => '2026-08-01', 'searches' => 100, 'zeroSearches' => 0, 'inPeriod' => true],
            ['bucket' => '2026-08-02', 'searches' => 40, 'zeroSearches' => 10, 'inPeriod' => true],
        ];

        $this->assertSame(25.0, $this->controller->getChart()[1]['zeroHeight']);
    }

    public function testTheAxisNeedsOnlyTheTallestBar(): void
    {
        $this->series = [
            ['bucket' => '2026-08-01', 'searches' => 40, 'zeroSearches' => 0, 'inPeriod' => true],
            ['bucket' => '2026-08-02', 'searches' => 900, 'zeroSearches' => 0, 'inPeriod' => true],
            ['bucket' => '2026-08-03', 'searches' => 120, 'zeroSearches' => 0, 'inPeriod' => true],
        ];

        $this->assertSame(900, $this->controller->getChartMax());
    }

    public function testAnEmptyChartHasNoScale(): void
    {
        $this->assertSame(0, $this->controller->getChartMax());
    }

    /**
     * The series reaches back before the period so the current month has
     * something to be compared against; those bars are marked as outside it.
     */
    public function testBarsKnowWhetherTheyAreInsideThePeriod(): void
    {
        $this->series = [
            ['bucket' => '2026-07-01', 'searches' => 10, 'zeroSearches' => 0, 'inPeriod' => false],
            ['bucket' => '2026-08-01', 'searches' => 10, 'zeroSearches' => 0, 'inPeriod' => true],
        ];

        $chart = $this->controller->getChart();

        $this->assertFalse($chart[0]['inPeriod']);
        $this->assertTrue($chart[1]['inPeriod']);
    }

    /**
     * Every column of a series row arrives from MySQL as a string, the flag
     * included - "0" is a non-empty string, and reading it as a boolean
     * without converting would mark every bar as inside the period.
     */
    public function testASeriesRowIsReadTheWayTheDatabaseHandsItOver(): void
    {
        $this->series = [
            ['bucket' => '2026-07-01', 'searches' => '120', 'zeroSearches' => '30', 'inPeriod' => '0'],
            ['bucket' => '2026-08-01', 'searches' => '60', 'zeroSearches' => '0', 'inPeriod' => '1'],
        ];

        $chart = $this->controller->getChart();

        $this->assertSame(120, $chart[0]['searches']);
        $this->assertSame(30, $chart[0]['zeroSearches']);
        $this->assertSame(100.0, $chart[0]['height']);
        $this->assertSame(50.0, $chart[1]['height']);
        $this->assertFalse($chart[0]['inPeriod']);
        $this->assertTrue($chart[1]['inPeriod']);
    }

    public function testADailyBarIsLabelledWithItsDate(): void
    {
        $this->withRequest(['logPeriod' => Period::MONTH]);
        $this->series = [['bucket' => '2026-08-04', 'searches' => 10, 'zeroSearches' => 2, 'inPeriod' => true]];

        $this->assertSame('04.08.', $this->controller->getChart()[0]['label']);
    }

    /**
     * A year is drawn in months, and the label is shortened - twelve full
     * month names do not fit under a chart. Shortened by characters: "März"
     * cut to three bytes is two characters and a broken one.
     */
    public function testAMonthlyBarIsLabelledWithAShortenedMonthName(): void
    {
        $this->withRequest(['logPeriod' => Period::YEAR]);
        $this->series = [['bucket' => '2026-03-01', 'searches' => 10, 'zeroSearches' => 2, 'inPeriod' => true]];

        $this->assertSame('Mär', $this->controller->getChart()[0]['label']);
    }

    /**
     * The whole sentence, because a bar's title is the only place its numbers
     * are written out - the bar itself is a rectangle.
     */
    public function testABarCarriesItsNumbersAsATitle(): void
    {
        $this->series = [['bucket' => '2026-08-04', 'searches' => 1200, 'zeroSearches' => 180, 'inPeriod' => true]];

        $this->assertSame(
            '04.08.2026: 1.200 FOUN10_EASYSEARCH_LOG_SEARCHES, 180 FOUN10_EASYSEARCH_LOG_WITHOUT_HITS',
            $this->controller->getChart()[0]['title']
        );
    }

    public function testAMonthlyBarIsTitledWithItsMonthAndYear(): void
    {
        $this->withRequest(['logPeriod' => Period::YEAR]);
        $this->series = [['bucket' => '2026-03-01', 'searches' => 10, 'zeroSearches' => 2, 'inPeriod' => true]];

        $this->assertStringStartsWith('März 2026: ', $this->controller->getChart()[0]['title']);
    }

    /**
     * The month number comes from a date, so it is always in range - but the
     * clamp is what keeps a language key that does not exist out of the
     * translation, and an out-of-range key renders as itself.
     */
    public function testAMonthOutsideTheYearIsClampedIntoIt(): void
    {
        $this->assertSame('Januar', $this->controller->getMonthNamePublic(0));
        $this->assertSame('Januar', $this->controller->getMonthNamePublic(-5));
        $this->assertSame('Dezember', $this->controller->getMonthNamePublic(13));
        $this->assertSame('Dezember', $this->controller->getMonthNamePublic(99));
    }

    // ---------------------------------------------------------------
    // reading longer than the list is shown
    // ---------------------------------------------------------------

    /**
     * Junk is dropped after the database has answered, so the query asks for
     * more than the list shows - otherwise a top ten arrives with four rows in
     * it and no hint why.
     */
    public function testTheQueryReadsFurtherThanTheListShows(): void
    {
        $this->withRequest(['logLimit' => '25']);
        $this->topTerms = $this->terms(200);

        $this->controller->getTopTerms();

        $this->assertSame(100, $this->topCalls[0]['limit']);
    }

    public function testTheListIsStillCutToTheLengthThatWasAskedFor(): void
    {
        $this->topTerms = $this->terms(200);

        $this->assertCount(10, $this->controller->getTopTerms());
    }

    public function testARowThatIsNotASearchIsDropped(): void
    {
        $this->topTerms = [
            ['term' => 'winterjacke', 'searches' => 10],
            ['term' => '../../etc/passwd', 'searches' => 9],
        ];
        $this->suspicious = ['../../etc/passwd' => 'traversal'];

        $this->assertSame(['winterjacke'], array_column($this->controller->getTopTerms(), 'term'));
    }

    /**
     * The screen says how many rows are missing rather than quietly being
     * shorter.
     */
    public function testTheScreenCountsWhatItDropped(): void
    {
        $this->topTerms = [
            ['term' => 'winterjacke', 'searches' => 10],
            ['term' => '../../etc/passwd', 'searches' => 9],
            ['term' => '<script>', 'searches' => 8],
        ];
        $this->suspicious = ['../../etc/passwd' => 'traversal', '<script>' => 'markup'];

        $this->controller->getTopTerms();

        $this->assertSame(2, $this->controller->getSuspiciousCount());
    }

    /**
     * A merchant who wonders why a term is missing deserves an answer better
     * than an empty space.
     */
    public function testTheDroppedRowsCanBeAskedForWithTheReasonTheyWereDropped(): void
    {
        $this->withRequest(['showSuspicious' => '1']);
        $this->topTerms = [['term' => '../../etc/passwd', 'searches' => 9]];
        $this->suspicious = ['../../etc/passwd' => 'traversal'];

        $rows = $this->controller->getTopTerms();

        $this->assertCount(1, $rows);
        $this->assertSame('FOUN10_EASYSEARCH_LOG_SUSPICIOUS_TRAVERSAL', $rows[0]['suspicious']);
    }

    public function testOnlyAnExplicitOneShowsTheDroppedRows(): void
    {
        $this->withRequest(['showSuspicious' => 'yes']);

        $this->assertFalse($this->controller->isShowingSuspicious());
    }

    public function testARowThatIsASearchCarriesNoReason(): void
    {
        $this->withRequest(['showSuspicious' => '1']);
        $this->topTerms = [['term' => 'winterjacke', 'searches' => 10]];

        $this->assertArrayNotHasKey('suspicious', $this->controller->getTopTerms()[0]);
    }

    public function testARowWithNoTermAtAllIsStillChecked(): void
    {
        $this->topTerms = [['searches' => 10]];
        $this->suspicious = ['' => 'empty'];

        $this->assertSame([], $this->controller->getTopTerms());
    }

    /**
     * A term that is all digits comes back from the database as a string, but
     * a filter that is handed one has to be handed a string whatever the
     * driver did with it.
     */
    public function testANumericTermIsCheckedAndReportedAsText(): void
    {
        $this->topTerms = [['term' => 2024, 'searches' => 10]];
        $this->suspicious = ['2024' => 'numeric'];

        $this->assertSame([], $this->controller->getTopTerms());
    }

    public function testANumericZeroHitTermIsStillMatchedAgainstTheRules(): void
    {
        $this->zeroHitTerms = [['term' => 2024, 'searches' => 10]];
        $this->rules = [new SynonymRule(SynonymRule::TYPE_BOTH, '2024', 'jahrgang')];

        $this->assertTrue($this->controller->getZeroHitTerms()[0]['hasSynonym']);
    }

    // ---------------------------------------------------------------
    // the zero hit list
    // ---------------------------------------------------------------

    /**
     * A rule does not remove the term from this list: the stored hit count is
     * what the *last* search for it found, so the row only changes once
     * somebody searches the word again. Without the flag that reads as "the
     * rule did not work".
     */
    public function testAZeroHitRowSaysWhetherARuleAlreadyCoversIt(): void
    {
        $this->zeroHitTerms = [
            ['term' => 'bralette', 'searches' => 12],
            ['term' => 'wintermantel', 'searches' => 8],
        ];
        $this->rules = [new SynonymRule(SynonymRule::TYPE_ONEWAY, 'bralette', 'triangel')];

        $rows = $this->controller->getZeroHitTerms();

        $this->assertTrue($rows[0]['hasSynonym']);
        $this->assertFalse($rows[1]['hasSynonym']);
    }

    /**
     * Both sides of a two way rule, because typing either one reaches the
     * other.
     */
    public function testBothSidesOfATwoWayRuleCountAsCovered(): void
    {
        $this->zeroHitTerms = [['term' => 'triangel', 'searches' => 12]];
        $this->rules = [new SynonymRule(SynonymRule::TYPE_BOTH, 'bralette', 'triangel')];

        $this->assertTrue($this->controller->getZeroHitTerms()[0]['hasSynonym']);
    }

    /**
     * Only the term side of a one way rule, because that is the only direction
     * it broadens - somebody searching the wider word is not asking for the
     * narrower one.
     */
    public function testOnlyTheTermSideOfAOneWayRuleCountsAsCovered(): void
    {
        $this->zeroHitTerms = [['term' => 'triangel', 'searches' => 12]];
        $this->rules = [new SynonymRule(SynonymRule::TYPE_ONEWAY, 'bralette', 'triangel')];

        $this->assertFalse($this->controller->getZeroHitTerms()[0]['hasSynonym']);
    }

    /**
     * Matched on the normalised form, which is how the search matches them
     * too - otherwise a rule written in lower case would not be recognised
     * beside a term somebody typed in capitals.
     */
    public function testCoverageIsMatchedTheWayTheSearchMatches(): void
    {
        $this->zeroHitTerms = [['term' => '  BRALETTE ', 'searches' => 12]];
        $this->rules = [new SynonymRule(SynonymRule::TYPE_BOTH, 'Bralette', 'triangel')];

        $this->assertTrue($this->controller->getZeroHitTerms()[0]['hasSynonym']);
    }

    // ---------------------------------------------------------------
    // the period being reported on
    // ---------------------------------------------------------------

    public function testThePeriodComesFromTheRequest(): void
    {
        $this->withRequest(['logPeriod' => Period::YEAR]);

        $this->assertSame(Period::YEAR, $this->controller->getPeriodName());
    }

    public function testAPeriodNobodyOffersIsNotOne(): void
    {
        $this->withRequest(['logPeriod' => 'decade']);

        $this->assertSame(Period::MONTH, $this->controller->getPeriodName());
    }

    public function testTheMonthIsReportedOnWhenNoPeriodWasChosen(): void
    {
        $this->assertSame(Period::MONTH, $this->controller->getPeriodName());
    }

    public function testAnArrayPeriodIsNotAPeriod(): void
    {
        $this->withRequest(['logPeriod' => [Period::YEAR]]);

        $this->assertSame(Period::MONTH, $this->controller->getPeriodName());
    }

    public function testEveryPeriodIsOfferedWithItsOwnLabel(): void
    {
        $periods = $this->controller->getPeriods();

        $this->assertSame(Period::getNames(), array_column($periods, 'value'));
        $this->assertContains('FOUN10_EASYSEARCH_LOG_PERIOD_MONTH', array_column($periods, 'label'));
    }

    /**
     * "August 2026" rather than "month", which would leave the reader to work
     * out which one.
     */
    public function testThePeriodIsNamedInWordsAboveTheNumbers(): void
    {
        $this->withRequest(['logPeriod' => Period::MONTH]);

        $label = $this->controller->getPeriodLabel();

        $this->assertMatchesRegularExpression('/^\p{L}+ \d{4}$/u', $label);
    }

    public function testADayIsNamedByItsDate(): void
    {
        $this->withRequest(['logPeriod' => Period::DAY]);

        $this->assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4}$/', $this->controller->getPeriodLabel());
    }

    public function testAYearIsNamedByItsYear(): void
    {
        $this->withRequest(['logPeriod' => Period::YEAR]);

        $this->assertMatchesRegularExpression('/^\d{4}$/', $this->controller->getPeriodLabel());
    }

    // ---------------------------------------------------------------
    // how long the lists are
    // ---------------------------------------------------------------

    public function testTheLengthComesFromTheRequest(): void
    {
        $this->withRequest(['logLimit' => '50']);

        $this->assertSame(50, $this->controller->getLimit());
    }

    /**
     * The switch offers three lengths, and anything else is not one of them -
     * the number multiplies the over-read, so an unbounded one is an
     * unbounded query.
     */
    public function testALengthNobodyOffersIsNotOne(): void
    {
        $this->withRequest(['logLimit' => '100000']);

        $this->assertSame(10, $this->controller->getLimit());
    }

    public function testTheDefaultLengthIsWhatFitsBesideTheChart(): void
    {
        $this->assertSame(10, $this->controller->getLimit());
        $this->assertSame([10, 25, 50], $this->controller->getLimits());
    }

    public function testAnArrayLengthIsNotALength(): void
    {
        $this->withRequest(['logLimit' => ['50']]);

        $this->assertSame(10, $this->controller->getLimit());
    }

    // ---------------------------------------------------------------
    // which scope is reported on
    // ---------------------------------------------------------------

    public function testTheChosenLanguageIsReportedOnWhenTheShopServesIt(): void
    {
        $this->languages = [1 => [['id' => 0, 'abbr' => 'de', 'name' => 'de'], ['id' => 1, 'abbr' => 'en', 'name' => 'en']]];
        $this->withRequest(['logLang' => '1']);

        $this->assertSame(1, $this->controller->getEditLanguageId());
    }

    public function testALanguageTheShopDoesNotServeIsIgnored(): void
    {
        $this->withRequest(['logLang' => '7']);

        $this->assertSame(0, $this->controller->getEditLanguageId());
    }

    /**
     * Falls back to the first active language rather than to zero, for the
     * same reason as the index screen.
     */
    public function testAShopWhoseFirstLanguageIsNotZeroReportsOnItsOwn(): void
    {
        $this->languages = [1 => [['id' => 3, 'abbr' => 'it', 'name' => 'it']]];

        $this->assertSame(3, $this->controller->getEditLanguageId());
    }

    public function testAShopServingNoLanguageFallsBackToZero(): void
    {
        $this->languages = [];

        $this->assertSame(0, $this->controller->getEditLanguageId());
    }

    public function testAnArrayLanguageIsNotSilentlyLanguageOne(): void
    {
        $this->languages = [1 => [['id' => 0, 'abbr' => 'de', 'name' => 'de'], ['id' => 1, 'abbr' => 'en', 'name' => 'en']]];
        $this->withRequest(['logLang' => ['1']]);

        $this->assertSame(0, $this->controller->getEditLanguageId());
    }

    public function testTheScopeReachesTheQuery(): void
    {
        $this->controller->currentShopId = 2;
        $this->languages = [2 => [['id' => 1, 'abbr' => 'en', 'name' => 'en']]];

        $this->controller->getTopTerms();

        $this->assertSame(2, $this->topCalls[0]['shopId']);
        $this->assertSame(1, $this->topCalls[0]['langId']);
    }

    // ---------------------------------------------------------------
    // a log table that is not there yet
    // ---------------------------------------------------------------

    /**
     * A report is not worth taking the backend down for. The table does not
     * exist until the migration has run, and this screen is reachable before
     * that.
     */
    public function testAMissingLogTableLeavesTheScreenStandingAndEmpty(): void
    {
        $this->logFails = true;

        $this->assertSame(
            ['searches' => 0, 'terms' => 0, 'zeroSearches' => 0, 'zeroTerms' => 0, 'zeroShare' => 0.0],
            $this->controller->getSummary()
        );
        $this->assertSame([], $this->controller->getChart());
        $this->assertSame([], $this->controller->getTopTerms());
        $this->assertSame([], $this->controller->getZeroHitTerms());
    }

    public function testAMissingLogTableIsWrittenToTheLogRatherThanSwallowedSilently(): void
    {
        $this->logFails = true;

        $this->controller->getSummary();

        $this->assertStringContainsString(
            'could not read the search log - Table foun10easysearchlog does not exist',
            $this->controller->loggedErrors[0]
        );
    }

    // ---------------------------------------------------------------
    // formatting the template asks for
    // ---------------------------------------------------------------

    public function testNumbersAreFormattedInOnePlaceForTheWholeScreen(): void
    {
        $this->assertSame('1.234.567', $this->controller->formatNumber(1234567));
        $this->assertSame('0', $this->controller->formatNumber(0));
        $this->assertSame('1.235', $this->controller->formatNumber(1234.6));
    }

    public function testADateIsRenderedAsTheBackendRendersThemElsewhere(): void
    {
        $this->assertSame('formatted:2026-08-04 10:00:00', $this->controller->formatDate('2026-08-04 10:00:00'));
    }

    /**
     * A term that was never searched has no date, and the zero date is not one
     * either - formatting it produces something that looks like a real, and
     * very old, search.
     */
    public function testSomethingThatIsNotADateIsShownAsNothing(): void
    {
        $this->assertSame('', $this->controller->formatDate(null));
        $this->assertSame('', $this->controller->formatDate(''));
        $this->assertSame('', $this->controller->formatDate('0000-00-00 00:00:00'));
    }
}
