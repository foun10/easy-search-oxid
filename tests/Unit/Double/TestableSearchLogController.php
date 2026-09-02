<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Controller\Admin\SearchLogController;
use RuntimeException;
use Throwable;

/**
 * SearchLogController with the request, the shop, the container and the
 * translations supplied by the test.
 *
 * The report is a pile of arithmetic over rows from one table - shares, bar
 * heights, an over-read that has to survive filtering - and none of it needs a
 * shop. What it does need is a log that can also *fail*, because the table may
 * not exist yet, and a screen that takes the backend down over a missing report
 * table is worse than the missing report.
 */
class TestableSearchLogController extends SearchLogController
{
    public FakeRequest $request;

    public int $currentShopId = 1;

    /** @var array<string, object> Container entries, keyed by service id */
    public array $services = [];

    /** @var string[] Language keys that were asked for, in order */
    public array $translated = [];

    /** @var string[] Messages that went to the log */
    public array $loggedErrors = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(array $parameters = [])
    {
        $this->request = new FakeRequest($parameters);
    }

    protected function getRequest()
    {
        return $this->request;
    }

    protected function getCurrentShopId(): int
    {
        return $this->currentShopId;
    }

    /**
     * The key back rather than a translation, so a test can assert which key
     * was asked for - except for the month names, which come back as real
     * German ones. The chart shortens them, and shortening is exactly the kind
     * of thing an ASCII placeholder would hide.
     */
    protected function translate(string $key): string
    {
        $this->translated[] = $key;

        return self::MONTHS[$key] ?? $key;
    }

    private const MONTHS = [
        'FOUN10_EASYSEARCH_LOG_MONTH_1' => 'Januar',
        'FOUN10_EASYSEARCH_LOG_MONTH_2' => 'Februar',
        'FOUN10_EASYSEARCH_LOG_MONTH_3' => 'März',
        'FOUN10_EASYSEARCH_LOG_MONTH_4' => 'April',
        'FOUN10_EASYSEARCH_LOG_MONTH_5' => 'Mai',
        'FOUN10_EASYSEARCH_LOG_MONTH_6' => 'Juni',
        'FOUN10_EASYSEARCH_LOG_MONTH_7' => 'Juli',
        'FOUN10_EASYSEARCH_LOG_MONTH_8' => 'August',
        'FOUN10_EASYSEARCH_LOG_MONTH_9' => 'September',
        'FOUN10_EASYSEARCH_LOG_MONTH_10' => 'Oktober',
        'FOUN10_EASYSEARCH_LOG_MONTH_11' => 'November',
        'FOUN10_EASYSEARCH_LOG_MONTH_12' => 'Dezember',
    ];

    public function getMonthNamePublic(int $month): string
    {
        return $this->getMonthName($month);
    }

    protected function formatDbDate(string $value): string
    {
        return 'formatted:' . $value;
    }

    protected function logError(string $message, Throwable $exception): void
    {
        $this->loggedErrors[] = $message;
    }

    protected function getService(string $id): object
    {
        return $this->services[$id]
            ?? throw new RuntimeException('no service registered for ' . $id);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    public function filterPublic(array $rows): array
    {
        return $this->filter($rows);
    }
}
