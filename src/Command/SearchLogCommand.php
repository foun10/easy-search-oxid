<?php
declare(strict_types=1);

namespace foun10\EasySearch\Command;

use foun10\EasySearch\Log\Period;
use foun10\EasySearch\Log\SearchLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * What customers searched for, and what found them nothing.
 *
 * The second list is the one to act on. A term that returns nothing is either a
 * word the catalogue does not use - which a synonym rule fixes, and this module
 * has synonym rules - or a product that is missing. Either way it is a customer
 * saying what they wanted, which nothing else in the shop reports.
 *
 * The numbers only start on the day the logging was deployed, and they count
 * whatever reaches the search page, bots included.
 */
class SearchLogCommand extends Command
{
    protected const DEFAULT_DAYS = 30;
    protected const DEFAULT_LIMIT = 25;

    public function __construct(
        protected SearchLog $searchLog
    ) {
        parent::__construct(null);
    }

    public function configure(): void
    {
        $this
            ->setName('foun10:easysearch:log')
            ->setDescription('Shows the most used search terms and the ones that find nothing')
            // No default on shop-id: the OXID console defines it globally, and
            // Symfony only tolerates a redefinition that matches exactly.
            ->addOption('shop-id', null, InputOption::VALUE_OPTIONAL, 'Shop to report on (default 1)')
            ->addOption('lang-id', null, InputOption::VALUE_OPTIONAL, 'Language to report on (default 0)')
            ->addOption('days', null, InputOption::VALUE_OPTIONAL, 'How far back to look', (string) self::DEFAULT_DAYS)
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Terms per list', (string) self::DEFAULT_LIMIT)
            ->addOption('zero-only', null, InputOption::VALUE_NONE, 'Only the terms that find nothing');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $shopId = (int) ($input->getOption('shop-id') ?? 1);
        $langId = (int) ($input->getOption('lang-id') ?? 0);
        $days = max(1, (int) $input->getOption('days'));
        $limit = max(1, (int) $input->getOption('limit'));
        $period = Period::lastDays($days);

        try {
            $summary = $this->searchLog->getSummary($shopId, $langId, $period);
        } catch (Throwable $exception) {
            $style->error(
                'Could not read the search log: ' . $exception->getMessage()
                . ' - has the migration run?'
            );

            return Command::FAILURE;
        }

        $style->title(sprintf('Searches in shop %d, language %d, last %d days', $shopId, $langId, $days));

        if ($summary['searches'] === 0) {
            $style->warning('Nothing logged yet for this scope.');

            return Command::SUCCESS;
        }

        $this->reportSummary($summary, $style);

        if (!$input->getOption('zero-only')) {
            $this->reportTop($shopId, $langId, $period, $limit, $style);
        }

        $this->reportZeroHits($shopId, $langId, $period, $limit, $style);

        return Command::SUCCESS;
    }

    /**
     * @param array{searches: int, terms: int, zeroSearches: int, zeroTerms: int} $summary
     */
    protected function reportSummary(array $summary, SymfonyStyle $style): void
    {
        $share = $summary['searches'] > 0
            ? $summary['zeroSearches'] / $summary['searches'] * 100
            : 0.0;

        $style->text(sprintf(
            '%s searches over %s distinct terms. %s of them found nothing (%.1f%%), across %s terms.',
            number_format($summary['searches']),
            number_format($summary['terms']),
            number_format($summary['zeroSearches']),
            $share,
            number_format($summary['zeroTerms'])
        ));
        $style->newLine();
    }

    protected function reportTop(int $shopId, int $langId, Period $period, int $limit, SymfonyStyle $style): void
    {
        $rows = [];

        foreach ($this->searchLog->getTopTerms($shopId, $langId, $period, $limit) as $row) {
            $rows[] = [
                (string) $row['term'],
                number_format((int) $row['searches']),
                number_format((int) $row['hits']),
                (string) $row['lastSeen'],
            ];
        }

        $style->section('Most searched');
        $style->table(['term', 'searches', 'hits', 'last seen'], $rows);
    }

    protected function reportZeroHits(int $shopId, int $langId, Period $period, int $limit, SymfonyStyle $style): void
    {
        $rows = [];

        foreach ($this->searchLog->getZeroHitTerms($shopId, $langId, $period, $limit) as $row) {
            $rows[] = [
                (string) $row['term'],
                number_format((int) $row['searches']),
                (string) $row['lastSeen'],
                (string) $row['corrected'],
            ];
        }

        $style->section('Found nothing');

        if ($rows === []) {
            $style->text('Every logged term found something.');

            return;
        }

        $style->table(['term', 'searches', 'last seen', 'correction offered'], $rows);
        $style->text('Each of these is a synonym rule waiting to be written, or a product that is missing.');
    }
}
