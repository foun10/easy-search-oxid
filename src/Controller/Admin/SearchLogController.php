<?php
declare(strict_types=1);

namespace foun10\EasySearch\Controller\Admin;

use foun10\EasySearch\Core\RequestValues;
use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Core\SynonymConfiguration;
use foun10\EasySearch\Correction\Normalizer;
use foun10\EasySearch\Log\Period;
use foun10\EasySearch\Log\SearchLog;
use foun10\EasySearch\Log\TermFilter;
use OxidEsales\Eshop\Application\Controller\Admin\AdminController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Throwable;

/**
 * foun10 -> Suche -> Auswertung
 *
 * What customers searched for, how often, and what found them nothing.
 *
 * A read-only screen. Everything on it comes out of foun10easysearchlog, which is
 * counted per term and day - so the smallest period is a day, there is no hour
 * view to be had, and nothing here can be traced back to a person because
 * nothing about a person was ever stored.
 *
 * The zero hit list is the point of the screen. Every row on it is a customer
 * who wanted something and left without it, and each one is either a synonym
 * rule waiting to be written or a product that is missing - which is why the
 * rows carry a button straight into the synonym screen.
 *
 * Rows that are not searches at all are dropped on the way in (see TermFilter)
 * and, for anything logged before that filter existed, again on the way out.
 * They can be shown on request: a merchant who wonders why a term is missing
 * deserves an answer better than an empty space.
 */
class SearchLogController extends AdminController
{
    use RequestValues;

    protected $_sThisTemplate = '@foun10EasySearch/admin/searchlog';

    /**
     * Rows per list. Ten is what was asked for and what fits on a screen
     * beside the chart; the switch goes further for the zero hit list, which
     * is the one that is actually worked through.
     */
    protected const LIMITS = [10, 25, 50];
    protected const DEFAULT_LIMIT = 10;

    /**
     * Read this many times the wanted rows before filtering, so a page of ten
     * still holds ten once the junk is gone. Bounded, because the alternative
     * is reading the whole vocabulary to fill a top ten.
     */
    protected const OVERREAD = 4;

    /**
     * How many rows the filter removed from the lists on screen, so the screen
     * can say so instead of quietly being shorter.
     */
    protected int $suspiciousCount = 0;

    /**
     * The summary, read once: the template asks for it, and hasData() asks
     * again to decide whether to render anything at all.
     *
     * @var array<string, float|int>|null
     */
    protected ?array $summary = null;

    /**
     * The chart, read once: the template draws it and then asks for its scale.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $chart = null;

    /**
     * Normalised terms a synonym rule already covers, read once per request.
     *
     * @var array<string, true>|null
     */
    protected ?array $covered = null;

    /**
     * @return array{searches: int, terms: int, zeroSearches: int, zeroTerms: int, zeroShare: float}
     */
    public function getSummary(): array
    {
        if ($this->summary !== null) {
            return $this->summary;
        }

        $summary = $this->read(
            fn (SearchLog $log): array => $log->getSummary(
                $this->getEditShopId(),
                $this->getEditLanguageId(),
                $this->getPeriod()
            ),
            ['searches' => 0, 'terms' => 0, 'zeroSearches' => 0, 'zeroTerms' => 0]
        );

        $summary['zeroShare'] = $summary['searches'] > 0
            ? round($summary['zeroSearches'] / $summary['searches'] * 100, 1)
            : 0.0;

        $this->summary = $summary;

        return $summary;
    }

    /**
     * The chart: one bar per day or per month, already scaled.
     *
     * Heights are worked out here rather than in the template because the
     * scale is a property of the whole series - a bar cannot know how tall it
     * should be on its own.
     *
     * @return array<int, array{label: string, title: string, searches: int, zeroSearches: int,
     *     height: float, zeroHeight: float, inPeriod: bool}>
     */
    public function getChart(): array
    {
        if ($this->chart !== null) {
            return $this->chart;
        }

        $period = $this->getPeriod();

        $series = $this->read(
            fn (SearchLog $log): array => $log->getSeries(
                $this->getEditShopId(),
                $this->getEditLanguageId(),
                $period
            ),
            []
        );

        $highest = 0;

        foreach ($series as $point) {
            $highest = max($highest, (int) $point['searches']);
        }

        $bars = [];

        foreach ($series as $point) {
            $searches = (int) $point['searches'];
            $zero = (int) $point['zeroSearches'];

            $bars[] = [
                'label' => $this->formatBucket((string) $point['bucket'], $period),
                'title' => $this->formatBarTitle((string) $point['bucket'], $period, $searches, $zero),
                'searches' => $searches,
                'zeroSearches' => $zero,
                // A day with searches always draws something, so an empty bar
                // means no searches rather than very few.
                // Cast because the tallest bar divides out to an integer and
                // max() then hands one back, which makes the one value the
                // template writes into a percentage a different type from
                // every other.
                'height' => $highest > 0 && $searches > 0 ? (float) max(2.0, $searches / $highest * 100) : 0.0,
                'zeroHeight' => $searches > 0 ? (float) ($zero / $searches * 100) : 0.0,
                'inPeriod' => (bool) $point['inPeriod'],
            ];
        }

        $this->chart = $bars;

        return $bars;
    }

    /**
     * The tallest bar in the chart, which is the only number the axis needs -
     * a scale with one label is a scale a merchant can read at a glance.
     */
    public function getChartMax(): int
    {
        $highest = 0;

        foreach ($this->getChart() as $bar) {
            $highest = max($highest, (int) $bar['searches']);
        }

        return $highest;
    }

    /**
     * The most used terms of the period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopTerms(): array
    {
        $limit = $this->getLimit();

        $rows = $this->read(
            fn (SearchLog $log): array => $log->getTopTerms(
                $this->getEditShopId(),
                $this->getEditLanguageId(),
                $this->getPeriod(),
                $limit * self::OVERREAD
            ),
            []
        );

        return $this->take($this->filter($rows), $limit);
    }

    /**
     * The terms that found nothing, which is the list to act on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getZeroHitTerms(): array
    {
        $limit = $this->getLimit();

        $rows = $this->read(
            fn (SearchLog $log): array => $log->getZeroHitTerms(
                $this->getEditShopId(),
                $this->getEditLanguageId(),
                $this->getPeriod(),
                $limit * self::OVERREAD
            ),
            []
        );

        $rows = $this->take($this->filter($rows), $limit);

        foreach ($rows as $index => $row) {
            $rows[$index]['hasSynonym'] = $this->hasSynonym((string) $row['term']);
        }

        return $rows;
    }

    /**
     * Whether a synonym rule already covers this term.
     *
     * A rule does not remove the term from this list: the hit count stored with
     * it is what the last search for it found, so the row only changes once
     * somebody searches the word again. Without this flag that reads as "the
     * rule did not work", and the row keeps inviting a second rule for a word
     * that already has one.
     */
    protected function hasSynonym(string $term): bool
    {
        return isset($this->getCoveredTerms()[$this->getNormalizer()->normalize($term)]);
    }

    /**
     * The terms the shop's active rules expand.
     *
     * Both sides of a two way rule, because typing either one reaches the
     * other. Only the term side of a one way rule, because that is the only
     * direction it broadens - see SynonymRule.
     *
     * @return array<string, true>
     */
    protected function getCoveredTerms(): array
    {
        if ($this->covered !== null) {
            return $this->covered;
        }

        $normalizer = $this->getNormalizer();
        $covered = [];

        /** @var SynonymConfiguration $configuration */
        $configuration = $this->getService(SynonymConfiguration::class);

        foreach ($configuration->getActiveRules($this->getEditShopId(), $this->getEditLanguageId()) as $rule) {
            $terms = [$rule->getTerm()];

            if ($rule->isTwoWay()) {
                $terms = array_merge($terms, $rule->getSynonymList());
            }

            foreach ($terms as $term) {
                $normalized = $normalizer->normalize($term);

                if ($normalized !== '') {
                    $covered[$normalized] = true;
                }
            }
        }

        return $this->covered = $covered;
    }

    protected function getNormalizer(): Normalizer
    {
        /** @var Normalizer $normalizer */
        $normalizer = $this->getService(Normalizer::class);

        return $normalizer;
    }

    /**
     * How many rows the lists on screen are missing because they were not
     * searches. Only meaningful once the lists have been asked for, so the
     * template reads it last.
     */
    public function getSuspiciousCount(): int
    {
        return $this->suspiciousCount;
    }

    public function isShowingSuspicious(): bool
    {
        return $this->getRequest()->getRequestEscapedParameter('showSuspicious') === '1';
    }

    /**
     * The period the screen reports on, from the request.
     *
     * @return Period
     */
    public function getPeriod(): Period
    {
        return Period::named((string) $this->getPeriodName());
    }

    public function getPeriodName(): string
    {
        $requested = $this->toString($this->getRequest()->getRequestEscapedParameter('logPeriod'));

        return in_array($requested, Period::getNames(), true) ? $requested : Period::MONTH;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getPeriods(): array
    {
        $periods = [];

        foreach (Period::getNames() as $name) {
            $periods[] = [
                'value' => $name,
                'label' => $this->translate('FOUN10_EASYSEARCH_LOG_PERIOD_' . strtoupper($name)),
            ];
        }

        return $periods;
    }

    /**
     * The period in words, for the headline above the numbers - "August 2026"
     * rather than "month", which would leave the reader to work out which one.
     */
    public function getPeriodLabel(): string
    {
        $period = $this->getPeriod();
        $time = strtotime($period->getFrom()) ?: time();

        return match ($period->getName()) {
            Period::DAY => date('d.m.Y', $time),
            Period::YEAR => date('Y', $time),
            default => $this->getMonthName((int) date('n', $time)) . ' ' . date('Y', $time),
        };
    }

    /**
     * @return array<int, int>
     */
    public function getLimits(): array
    {
        return self::LIMITS;
    }

    public function getLimit(): int
    {
        $requested = $this->toInt($this->getRequest()->getRequestEscapedParameter('logLimit'));

        return in_array($requested, self::LIMITS, true) ? $requested : self::DEFAULT_LIMIT;
    }

    /**
     * The languages this shop serves, for the switch above the numbers.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getLanguages(): array
    {
        $languages = [];

        /** @var ShopLanguages $shopLanguages */
        $shopLanguages = $this->getService(ShopLanguages::class);

        foreach ($shopLanguages->getActive($this->getEditShopId()) as $language) {
            $languages[] = ['id' => $language['id'], 'name' => $language['name']];
        }

        return $languages;
    }

    /**
     * Falls back to the first active language rather than to zero, for the same
     * reason as the index screen: a shop whose only language is not 0 would
     * otherwise open on a scope it does not have.
     */
    public function getEditLanguageId(): int
    {
        $available = array_column($this->getLanguages(), 'id');
        $requested = $this->getRequest()->getRequestEscapedParameter('logLang');

        if ($requested !== null && $requested !== '' && in_array($this->toInt($requested), $available, true)) {
            return $this->toInt($requested);
        }

        return $available === [] ? 0 : (int) $available[0];
    }

    public function getEditShopId(): int
    {
        return $this->getCurrentShopId();
    }

    /**
     * Thousands separators, German, in one place - the template asks for them
     * rather than each list formatting its own way.
     */
    public function formatNumber(int|float|string $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    /**
     * A timestamp as the backend writes them everywhere else.
     */
    public function formatDate(?string $value): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return '';
        }

        return $this->formatDbDate($value);
    }

    /**
     * Whether anything at all has been logged for this shop and language, so
     * the screen can explain an empty page instead of showing empty lists.
     */
    public function hasData(): bool
    {
        return $this->getSummary()['searches'] > 0;
    }

    /**
     * Reads through the log, answering with $fallback if the table is not there
     * yet - a report is not worth taking the backend down for.
     *
     * @template T
     *
     * @param callable(SearchLog): T $read
     * @param T $fallback
     *
     * @return T
     */
    protected function read(callable $read, mixed $fallback): mixed
    {
        try {
            /** @var SearchLog $log */
            $log = $this->getService(SearchLog::class);

            return $read($log);
        } catch (Throwable $exception) {
            $this->logError(
                'foun10EasySearch: could not read the search log - ' . $exception->getMessage(),
                $exception
            );

            return $fallback;
        }
    }

    /**
     * Drops the rows that are not searches, counting what it dropped.
     *
     * Applied on top of the filter the writer already runs, because rows
     * written before that filter existed are still in the table.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    protected function filter(array $rows): array
    {
        $filter = $this->getTermFilter();
        $show = $this->isShowingSuspicious();
        $kept = [];

        foreach ($rows as $row) {
            $reason = $filter->check((string) ($row['term'] ?? ''));

            if ($reason === null) {
                $kept[] = $row;

                continue;
            }

            $this->suspiciousCount++;

            if ($show) {
                $row['suspicious'] = $this->translateReason($reason);
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    protected function take(array $rows, int $limit): array
    {
        return array_slice($rows, 0, $limit);
    }

    protected function translateReason(string $reason): string
    {
        return $this->translate('FOUN10_EASYSEARCH_LOG_SUSPICIOUS_' . strtoupper($reason));
    }

    protected function getTermFilter(): TermFilter
    {
        /** @var TermFilter $filter */
        $filter = $this->getService(TermFilter::class);

        return $filter;
    }

    protected function formatBucket(string $bucket, Period $period): string
    {
        $time = strtotime($bucket) ?: time();

        return $period->isMonthly()
            ? mb_substr($this->getMonthName((int) date('n', $time)), 0, 3)
            : date('d.m.', $time);
    }

    protected function formatBarTitle(string $bucket, Period $period, int $searches, int $zero): string
    {
        $time = strtotime($bucket) ?: time();
        $when = $period->isMonthly()
            ? $this->getMonthName((int) date('n', $time)) . ' ' . date('Y', $time)
            : date('d.m.Y', $time);

        return sprintf(
            '%s: %s %s, %s %s',
            $when,
            $this->formatNumber($searches),
            $this->translate('FOUN10_EASYSEARCH_LOG_SEARCHES'),
            $this->formatNumber($zero),
            $this->translate('FOUN10_EASYSEARCH_LOG_WITHOUT_HITS')
        );
    }

    /**
     * Month names out of the backend's own language file, so the chart is
     * labelled in the language the admin is displayed in.
     */
    protected function getMonthName(int $month): string
    {
        return $this->translate('FOUN10_EASYSEARCH_LOG_MONTH_' . max(1, min(12, $month)));
    }

    /*
     * The shop touch points, kept apart from the reporting above. Each hands
     * back a scalar or a container entry rather than a Config, Language or
     * Utils object, so what the screen decides - which period and scope it
     * reports on, what the chart looks like, which rows are searches at all -
     * can be proven without a shop.
     */

    /**
     * @return \OxidEsales\Eshop\Core\Request
     */
    protected function getRequest()
    {
        return Registry::getRequest();
    }

    protected function getCurrentShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    protected function translate(string $key): string
    {
        return (string) Registry::getLang()->translateString($key);
    }

    protected function formatDbDate(string $value): string
    {
        return (string) Registry::getUtilsDate()->formatDBDate($value, true);
    }

    protected function logError(string $message, Throwable $exception): void
    {
        Registry::getLogger()->error($message, ['exception' => $exception]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function getService(string $id): object
    {
        return ContainerFactory::getInstance()->getContainer()->get($id);
    }
}
