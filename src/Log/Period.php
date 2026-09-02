<?php
declare(strict_types=1);

namespace foun10\EasySearch\Log;

/**
 * The stretch of time a search report covers.
 *
 * Calendar periods, not rolling windows: "der Monat" in a shop means the month
 * on the calendar, and a merchant comparing this month against the last one
 * cannot do that with a window that moves under them every night.
 *
 * The chart is allowed to reach further back than the report - see
 * getSeriesFrom(). A single day is one bar, which shows nothing, so the day
 * report draws a fortnight of context around it.
 *
 * The log table aggregates per day, so a day is the smallest period there is
 * and the only granularities available are day and month.
 */
class Period
{
    public const DAY = 'day';
    public const MONTH = 'month';
    public const YEAR = 'year';

    public const GRANULARITY_DAY = 'day';
    public const GRANULARITY_MONTH = 'month';

    /**
     * Days of context the single-day report draws behind itself.
     */
    protected const DAY_CONTEXT = 13;

    protected function __construct(
        protected string $name,
        protected string $from,
        protected string $to,
        protected string $granularity,
        protected string $seriesFrom
    ) {
    }

    /**
     * @return string[]
     */
    public static function getNames(): array
    {
        return [self::DAY, self::MONTH, self::YEAR];
    }

    /**
     * The named calendar period containing $today, falling back to the month
     * for anything unknown - a report is the wrong place to fail over a
     * parameter somebody typed into the URL.
     */
    public static function named(string $name, ?string $today = null): self
    {
        $today = $today ?? date('Y-m-d');
        $time = strtotime($today) ?: time();

        return match ($name) {
            self::DAY => new self(
                self::DAY,
                $today,
                $today,
                self::GRANULARITY_DAY,
                date('Y-m-d', strtotime('-' . self::DAY_CONTEXT . ' days', $time) ?: $time)
            ),
            self::YEAR => new self(
                self::YEAR,
                date('Y-01-01', $time),
                date('Y-12-31', $time),
                self::GRANULARITY_MONTH,
                date('Y-01-01', $time)
            ),
            default => new self(
                self::MONTH,
                date('Y-m-01', $time),
                date('Y-m-t', $time),
                self::GRANULARITY_DAY,
                date('Y-m-01', $time)
            ),
        };
    }

    /**
     * A rolling window, for the console report and the benchmark - both ask
     * "the last n days" rather than for a calendar period.
     */
    public static function lastDays(int $days, ?string $today = null): self
    {
        $today = $today ?? date('Y-m-d');
        $time = strtotime($today) ?: time();
        $from = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days', $time) ?: $time);

        return new self(
            'days',
            $from,
            $today,
            $days > 62 ? self::GRANULARITY_MONTH : self::GRANULARITY_DAY,
            $from
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getTo(): string
    {
        return $this->to;
    }

    public function getGranularity(): string
    {
        return $this->granularity;
    }

    /**
     * Where the chart starts, which is at or before where the report does.
     */
    public function getSeriesFrom(): string
    {
        return $this->seriesFrom;
    }

    public function isMonthly(): bool
    {
        return $this->granularity === self::GRANULARITY_MONTH;
    }
}
