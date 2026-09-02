<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Log;

use foun10\EasySearch\Log\Period;
use PHPUnit\Framework\TestCase;

/**
 * The reporting window.
 *
 * Every case passes an explicit "today" rather than relying on the clock -
 * a period test that only passes in the middle of a long month is worse than
 * no test, because it fails on the 1st and gets marked flaky instead of read.
 */
class PeriodTest extends TestCase
{
    private const TODAY = '2026-08-31';

    public function testTheNamesAreTheThreeCalendarPeriods(): void
    {
        $this->assertSame(['day', 'month', 'year'], Period::getNames());
    }

    public function testASingleDayCoversOnlyThatDay(): void
    {
        $period = Period::named(Period::DAY, self::TODAY);

        $this->assertSame('day', $period->getName());
        $this->assertSame(self::TODAY, $period->getFrom());
        $this->assertSame(self::TODAY, $period->getTo());
        $this->assertSame(Period::GRANULARITY_DAY, $period->getGranularity());
    }

    /**
     * One bar is not a trend, so the day view draws a fortnight behind itself.
     * The series therefore starts before the period does, which is the whole
     * reason getSeriesFrom() exists separately from getFrom().
     */
    public function testTheDayViewDrawsAFortnightOfContextBehindItself(): void
    {
        $period = Period::named(Period::DAY, self::TODAY);

        $this->assertSame('2026-08-18', $period->getSeriesFrom());
        $this->assertNotSame($period->getFrom(), $period->getSeriesFrom());
    }

    public function testAMonthRunsFromTheFirstToTheLastDay(): void
    {
        $period = Period::named(Period::MONTH, self::TODAY);

        $this->assertSame('2026-08-01', $period->getFrom());
        $this->assertSame('2026-08-31', $period->getTo());
        $this->assertSame(Period::GRANULARITY_DAY, $period->getGranularity());
        $this->assertSame($period->getFrom(), $period->getSeriesFrom());
    }

    /**
     * The last day comes from the calendar, not from a fixed 30 or 31.
     */
    public function testAMonthEndsOnItsOwnLastDay(): void
    {
        $this->assertSame('2026-02-28', Period::named(Period::MONTH, '2026-02-10')->getTo());
        $this->assertSame('2024-02-29', Period::named(Period::MONTH, '2024-02-10')->getTo(), 'leap year');
        $this->assertSame('2026-04-30', Period::named(Period::MONTH, '2026-04-10')->getTo());
    }

    public function testAYearRunsAcrossTheWholeCalendarYearInMonths(): void
    {
        $period = Period::named(Period::YEAR, self::TODAY);

        $this->assertSame('2026-01-01', $period->getFrom());
        $this->assertSame('2026-12-31', $period->getTo());
        $this->assertSame(Period::GRANULARITY_MONTH, $period->getGranularity());
        $this->assertTrue($period->isMonthly());
    }

    public function testTheDayAndMonthViewsAreNotMonthly(): void
    {
        $this->assertFalse(Period::named(Period::DAY, self::TODAY)->isMonthly());
        $this->assertFalse(Period::named(Period::MONTH, self::TODAY)->isMonthly());
    }

    /**
     * A report is the wrong place to fail over a parameter somebody typed into
     * the URL, so anything unknown lands on the month rather than throwing.
     */
    public function testAnUnknownNameFallsBackToTheMonth(): void
    {
        $period = Period::named('nonsense', self::TODAY);

        $this->assertSame('month', $period->getName());
        $this->assertSame('2026-08-01', $period->getFrom());
    }

    /**
     * The rolling window is inclusive of today, so "the last day" is today
     * alone rather than today plus yesterday.
     */
    public function testARollingWindowIsInclusiveOfToday(): void
    {
        $period = Period::lastDays(1, self::TODAY);

        $this->assertSame(self::TODAY, $period->getFrom());
        $this->assertSame(self::TODAY, $period->getTo());
    }

    public function testARollingWindowCountsBackwardsFromToday(): void
    {
        $this->assertSame('2026-08-25', Period::lastDays(7, self::TODAY)->getFrom());
        $this->assertSame('2026-08-01', Period::lastDays(31, self::TODAY)->getFrom());
    }

    /**
     * Past roughly two months a daily chart is unreadable, so the granularity
     * switches. The boundary is pinned because it is the kind of number that
     * gets "tidied" to 60 or 90 by somebody who does not know why it is 62.
     */
    public function testTheRollingWindowSwitchesToMonthsPastTwoMonths(): void
    {
        $this->assertSame(Period::GRANULARITY_DAY, Period::lastDays(62, self::TODAY)->getGranularity());
        $this->assertSame(Period::GRANULARITY_MONTH, Period::lastDays(63, self::TODAY)->getGranularity());
    }

    public function testANonsensicalWindowLengthDoesNotRunIntoTheFuture(): void
    {
        $this->assertSame(self::TODAY, Period::lastDays(0, self::TODAY)->getFrom());
        $this->assertSame(self::TODAY, Period::lastDays(-5, self::TODAY)->getFrom());
    }

    public function testTheSeriesOfARollingWindowStartsWithTheWindow(): void
    {
        $period = Period::lastDays(30, self::TODAY);

        $this->assertSame($period->getFrom(), $period->getSeriesFrom());
    }
}
