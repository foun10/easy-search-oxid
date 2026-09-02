<?php
declare(strict_types=1);

namespace foun10\EasySearch\Log;

use foun10\EasySearch\Core\DatabaseHelper;
use OxidEsales\Eshop\Core\DatabaseProvider;

/**
 * Reads back what SearchLogger counted.
 *
 * Kept apart from the writer because the two have nothing in common: one runs
 * on every search page and must be as close to free as a write can be, the
 * other runs when somebody asks a question and can take its time.
 *
 * Every read is scoped by a Period, so the console report, the benchmark and
 * the backend screen ask the same question the same way and differ only in
 * which stretch of time they ask about.
 */
class SearchLog
{
    public const TABLE = 'foun10easysearchlog';

    /**
     * The terms customers use most.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopTerms(int $shopId, int $langId, Period $period, int $limit): array
    {
        return DatabaseHelper::fetchAll(
            'SELECT
                FOUN10TERMRAW AS term,
                SUM(FOUN10EASYSEARCHES) AS searches,
                MAX(FOUN10HITS) AS hits,
                MAX(FOUN10LASTSEEN) AS lastSeen
             FROM ' . self::TABLE . '
             WHERE ' . $this->getScope() . '
             GROUP BY FOUN10TERM
             ORDER BY searches DESC, term ASC
             LIMIT ' . max(1, $limit),
            $this->getParameters($shopId, $langId, $period)
        );
    }

    /**
     * The terms that found nothing.
     *
     * Judged on the most recent search for a term rather than on any of them:
     * a word that returned nothing last week and works today is not a gap any
     * more, and one that worked last week and fails today very much is.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getZeroHitTerms(int $shopId, int $langId, Period $period, int $limit): array
    {
        return DatabaseHelper::fetchAll(
            'SELECT
                FOUN10TERMRAW AS term,
                SUM(FOUN10EASYSEARCHES) AS searches,
                MAX(FOUN10LASTSEEN) AS lastSeen,
                MAX(FOUN10CORRECTED) AS corrected
             FROM ' . self::TABLE . '
             WHERE ' . $this->getScope() . '
             GROUP BY FOUN10TERM
             HAVING MAX(FOUN10DAY) = MAX(CASE WHEN FOUN10HITS = 0 THEN FOUN10DAY END)
             ORDER BY searches DESC, term ASC
             LIMIT ' . max(1, $limit),
            $this->getParameters($shopId, $langId, $period)
        );
    }

    /**
     * @return array{searches: int, terms: int, zeroSearches: int, zeroTerms: int}
     */
    public function getSummary(int $shopId, int $langId, Period $period): array
    {
        $rows = DatabaseHelper::fetchAll(
            'SELECT
                SUM(FOUN10EASYSEARCHES) AS searches,
                COUNT(DISTINCT FOUN10TERM) AS terms,
                SUM(CASE WHEN FOUN10HITS = 0 THEN FOUN10EASYSEARCHES ELSE 0 END) AS zeroSearches,
                COUNT(DISTINCT CASE WHEN FOUN10HITS = 0 THEN FOUN10TERM END) AS zeroTerms
             FROM ' . self::TABLE . '
             WHERE ' . $this->getScope(),
            $this->getParameters($shopId, $langId, $period)
        );

        $row = $rows[0] ?? [];

        return [
            'searches' => (int) ($row['searches'] ?? 0),
            'terms' => (int) ($row['terms'] ?? 0),
            'zeroSearches' => (int) ($row['zeroSearches'] ?? 0),
            'zeroTerms' => (int) ($row['zeroTerms'] ?? 0),
        ];
    }

    /**
     * Searches per day or per month, as one continuous series.
     *
     * Gaps are filled with zeros rather than skipped: a chart that leaves the
     * quiet days out draws them as if they had not happened, and a fortnight
     * with two silent days would look like a fortnight of twelve.
     *
     * Runs from the period's series start, which for a single day reaches
     * further back than the report itself - one bar is not a trend.
     *
     * @return array<int, array{bucket: string, searches: int, zeroSearches: int, terms: int, inPeriod: bool}>
     */
    public function getSeries(int $shopId, int $langId, Period $period): array
    {
        $bucket = $period->isMonthly()
            ? "DATE_FORMAT(FOUN10DAY, '%Y-%m-01')"
            : 'FOUN10DAY';

        $rows = DatabaseHelper::fetchAll(
            'SELECT
                ' . $bucket . ' AS bucket,
                SUM(FOUN10EASYSEARCHES) AS searches,
                SUM(CASE WHEN FOUN10HITS = 0 THEN FOUN10EASYSEARCHES ELSE 0 END) AS zeroSearches,
                COUNT(DISTINCT FOUN10TERM) AS terms
             FROM ' . self::TABLE . '
             WHERE OXSHOPID = :shopId
                AND FOUN10LANGID = :langId
                AND FOUN10DAY BETWEEN :from AND :to
             GROUP BY bucket
             ORDER BY bucket ASC',
            [
                ':shopId' => $shopId,
                ':langId' => $langId,
                ':from' => $period->getSeriesFrom(),
                ':to' => $period->getTo(),
            ]
        );

        $counted = [];

        foreach ($rows as $row) {
            $counted[(string) $row['bucket']] = [
                'searches' => (int) $row['searches'],
                'zeroSearches' => (int) $row['zeroSearches'],
                'terms' => (int) $row['terms'],
            ];
        }

        $series = [];

        foreach ($this->getBuckets($period) as $key) {
            $series[] = [
                'bucket' => $key,
                'searches' => $counted[$key]['searches'] ?? 0,
                'zeroSearches' => $counted[$key]['zeroSearches'] ?? 0,
                'terms' => $counted[$key]['terms'] ?? 0,
                // Whether this bar belongs to the period the numbers above the
                // chart are about, or is only the context around it.
                'inPeriod' => $key >= $period->getFrom(),
            ];
        }

        return $series;
    }

    /**
     * Terms to feed the benchmark, most used first - a real query mix rather
     * than an invented one.
     *
     * @return string[]
     */
    public function getBenchmarkTerms(int $shopId, int $langId, Period $period, int $limit): array
    {
        return (array) DatabaseProvider::getDb()->getCol(
            'SELECT FOUN10TERMRAW
             FROM ' . self::TABLE . '
             WHERE ' . $this->getScope() . '
             GROUP BY FOUN10TERM
             ORDER BY SUM(FOUN10EASYSEARCHES) DESC
             LIMIT ' . max(1, $limit),
            $this->getParameters($shopId, $langId, $period)
        );
    }

    /**
     * Every bucket the chart has an axis position for, the empty ones included.
     *
     * Stops at today: the current month has days left in it, and drawing them
     * as empty bars reports a collapse in traffic that has not happened.
     *
     * @return string[]
     */
    protected function getBuckets(Period $period): array
    {
        $today = date('Y-m-d');
        $last = min($period->getTo(), $today);
        $step = $period->isMonthly() ? '+1 month' : '+1 day';
        $cursor = $period->isMonthly()
            ? substr($period->getSeriesFrom(), 0, 7) . '-01'
            : $period->getSeriesFrom();

        $buckets = [];

        // Bounded rather than trusting the dates: a period is at most a year,
        // and a loop over dates that cannot end is worse than a short chart.
        for ($guard = 0; $guard < 400 && $cursor <= $last; $guard++) {
            $buckets[] = $cursor;
            $cursor = date('Y-m-d', strtotime($step, strtotime($cursor) ?: time()) ?: time());

            if ($period->isMonthly()) {
                $cursor = substr($cursor, 0, 7) . '-01';
            }
        }

        return $buckets;
    }

    protected function getScope(): string
    {
        return 'OXSHOPID = :shopId
                AND FOUN10LANGID = :langId
                AND FOUN10DAY BETWEEN :from AND :to';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getParameters(int $shopId, int $langId, Period $period): array
    {
        return [
            ':shopId' => $shopId,
            ':langId' => $langId,
            ':from' => $period->getFrom(),
            ':to' => $period->getTo(),
        ];
    }
}
