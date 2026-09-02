<?php
declare(strict_types=1);

namespace foun10\EasySearch\Command;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Index\DictionaryBuilder;
use foun10\EasySearch\Index\DocumentProvider;
use foun10\EasySearch\Index\IndexWriterInterface;
use foun10\EasySearch\Index\IndexWriterLocator;
use InvalidArgumentException;
use OxidEsales\Eshop\Core\Registry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Rebuilds the search index.
 *
 * Without options it rebuilds every shop and every language, which lets the
 * writer use the shadow table swap - the live index stays fully intact and is
 * replaced in one atomic step at the end. Passing --shop-id or --lang-id
 * narrows the run, and the writer then has to replace those rows in place.
 * That is the slower and less safe path, so prefer the full rebuild for
 * scheduled runs and keep the scoped one for fixing a single shop.
 *
 * Intended to run from cron, and after deployment alongside the existing
 * migration and configuration steps in ci/deploy.sh.
 *
 * Which backend is filled follows the shop's FOUN10EASYSEARCH_ENGINE setting unless
 * --engine says otherwise. Naming it explicitly is what keeps both indexes
 * current while a migration is being evaluated - and the benchmark command has
 * nothing to compare until they are.
 */
class ReindexCommand extends Command
{
    protected const DEFAULT_BATCH_SIZE = 500;

    /**
     * Resolved in execute() from --engine or from the shop's setting, so the
     * whole run writes into one backend.
     */
    protected ?IndexWriterInterface $indexWriter = null;

    public function __construct(
        protected ShopLanguages $shopLanguages,
        protected DocumentProvider $documentProvider,
        protected IndexWriterLocator $indexWriterLocator,
        protected DictionaryBuilder $dictionaryBuilder,
        protected ModuleSettings $moduleSettings
    ) {
        parent::__construct(null);
    }

    public function configure(): void
    {
        $this
            ->setName('foun10:easysearch:reindex')
            ->setDescription('Rebuilds the product search index')
            ->addOption(
                'shop-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limit to one shop; default is every shop'
            )
            ->addOption(
                'lang-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limit to one language; default is every ACTIVE language of the shop'
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_OPTIONAL,
                'Articles read and written per batch',
                (string) self::DEFAULT_BATCH_SIZE
            )
            ->addOption(
                'skip-dictionary',
                null,
                InputOption::VALUE_NONE,
                'Do not rebuild the typo tolerance dictionary afterwards'
            )
            ->addOption(
                'dictionary-only',
                null,
                InputOption::VALUE_NONE,
                'Only rebuild the dictionary from the existing index'
            )
            ->addOption(
                'categories-only',
                null,
                InputOption::VALUE_NONE,
                'Only rebuild the category assignments; cheap enough to cron several times a day'
            )
            ->addOption(
                'skip-categories',
                null,
                InputOption::VALUE_NONE,
                'Do not refresh the category assignments afterwards'
            )
            ->addOption(
                'force-categories',
                null,
                InputOption::VALUE_NONE,
                'Publish category assignments even when the source looks mid-import'
            )
            ->addOption(
                'engine',
                null,
                InputOption::VALUE_OPTIONAL,
                'Backend to fill: mysql or meilisearch; default is what the shop is configured for'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $scopes = $this->resolveScopes($input);

        try {
            $this->indexWriter = $this->resolveWriter($input);
        } catch (InvalidArgumentException $exception) {
            $style->error($exception->getMessage() . ' - known engines: '
                . implode(', ', $this->indexWriterLocator->getNames()));

            return Command::FAILURE;
        }

        if ($scopes === []) {
            $style->error('No shop/language combination to index');

            return Command::FAILURE;
        }

        if ($input->getOption('dictionary-only')) {
            $this->buildDictionaries($scopes, $style);

            return Command::SUCCESS;
        }

        // The cron entry point. Runs against the live index and touches nothing
        // else, so it is safe to fire while customers are searching.
        if ($input->getOption('categories-only')) {
            return $this->buildCategories($scopes, $style, $input->getOption('force-categories'))
                ? Command::SUCCESS
                : Command::FAILURE;
        }

        $isFullRebuild = $input->getOption('shop-id') === null
            && $input->getOption('lang-id') === null;

        $style->title('Rebuilding search index');
        $style->text(sprintf(
            '%d scope(s), batch size %d, engine: %s, mode: %s',
            count($scopes),
            $batchSize,
            $this->resolveEngineName($input),
            $isFullRebuild ? 'full rebuild with swap' : 'scoped in-place replace'
        ));

        // Counting every scope first costs one COUNT per scope but is what
        // makes a projection over the whole run possible - a per-scope bar
        // alone says nothing about when eight scopes will be done.
        $expected = $this->countScopes($scopes);
        $grandTotal = array_sum($expected);

        $style->text(sprintf('%s documents to index in total', number_format($grandTotal)));
        $style->newLine();

        $start = microtime(true);
        $total = 0;

        try {
            // An empty scope list tells the writer it may replace everything.
            $this->indexWriter->begin($isFullRebuild ? [] : $scopes);

            foreach ($scopes as $index => $scope) {
                $total += $this->indexScope($scope, $expected[$index], $batchSize, $style);
                $this->reportOverall($style, $total, $grandTotal, $start);
            }

            $this->indexWriter->commit();
        } catch (Throwable $exception) {
            $this->indexWriter->rollback();
            $style->error('Reindex failed, the live index was left untouched: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        $elapsed = microtime(true) - $start;

        $style->success(sprintf(
            '%s documents indexed in %s (%s docs/s)',
            number_format($total),
            Helper::formatTime($elapsed, 2),
            number_format($total / max($elapsed, 0.001))
        ));

        // After the swap, because the assignments are derived from whatever
        // group IDs are live in the index - reading them before the rename
        // would describe the index that just got replaced.
        if (!$input->getOption('skip-categories')) {
            $this->buildCategories($scopes, $style, $input->getOption('force-categories'));
        }

        if (!$input->getOption('skip-dictionary')) {
            $this->buildDictionaries($scopes, $style);
        }

        return Command::SUCCESS;
    }

    /**
     * Refreshes the category assignments of every scope.
     *
     * A refusal is not a crash - the old assignments are still serving - but it
     * must not pass silently either, or a cron that has been declining to
     * publish for a week looks exactly like one that is working.
     *
     * @param array<int, array{shopId: int, langId: int}> $scopes
     *
     * @return bool False when at least one scope refused to publish
     */
    protected function buildCategories(array $scopes, SymfonyStyle $style, bool $force): bool
    {
        $style->section('Category assignments');

        $published = true;

        foreach ($scopes as $scope) {
            $result = $this->indexWriter->rebuildCategories($scope['shopId'], $scope['langId'], $force);

            $message = sprintf(
                'Shop %d, language %d: %s',
                $scope['shopId'],
                $scope['langId'],
                $result->describe()
            );

            if ($result->isPublished()) {
                $style->text($message);

                continue;
            }

            $style->warning($message);
            $published = false;
        }

        return $published;
    }

    /**
     * @param array{shopId: int, langId: int} $scope
     */
    protected function indexScope(array $scope, int $expected, int $batchSize, SymfonyStyle $style): int
    {
        $shopId = $scope['shopId'];
        $langId = $scope['langId'];

        $style->section(sprintf(
            'Shop %d, language %d (%s articles)',
            $shopId,
            $langId,
            number_format($expected)
        ));

        $progress = $style->createProgressBar($expected);
        // %remaining% is Symfony's own projection: elapsed time over progress
        // so far, which settles down after the first few batches. It needs a
        // known total, and throws where there is none - so a scope that holds
        // nothing gets the plain format instead. An empty scope is ordinary
        // (a subshop just added, a language whose catalogue is not filled
        // yet), and it used to abort the whole run and roll back every scope
        // that had already been indexed.
        $progress->setFormat(
            $expected > 0
                ? ' %current%/%max% [%bar%] %percent:3s%%  %elapsed:6s% elapsed, ~%remaining:6s% left'
                : ' %current% [%bar%] %elapsed:6s% elapsed'
        );
        $progress->start();

        $buffer = [];
        $written = 0;

        foreach ($this->documentProvider->provide($shopId, $langId, $batchSize) as $document) {
            $buffer[] = $document;

            if (count($buffer) < $batchSize) {
                continue;
            }

            $this->indexWriter->write($buffer);
            $written += count($buffer);
            $progress->advance(count($buffer));
            $buffer = [];
        }

        if ($buffer !== []) {
            $this->indexWriter->write($buffer);
            $written += count($buffer);
            $progress->advance(count($buffer));
        }

        $progress->finish();
        $style->newLine(2);

        return $written;
    }

    /**
     * Article count per scope, in the same order as $scopes.
     *
     * @param array<int, array{shopId: int, langId: int}> $scopes
     *
     * @return array<int, int>
     */
    protected function countScopes(array $scopes): array
    {
        $counts = [];

        foreach ($scopes as $index => $scope) {
            $counts[$index] = $this->documentProvider->countArticles($scope['shopId'], $scope['langId']);
        }

        return $counts;
    }

    /**
     * Progress across every scope, not just the one that finished.
     *
     * The estimate is a straight extrapolation of the throughput so far. It is
     * honest about that: later scopes can be slower when a shop has more
     * variants, so treat it as an order of magnitude rather than a promise.
     */
    protected function reportOverall(
        SymfonyStyle $style,
        int $done,
        int $grandTotal,
        float $start
    ): void {
        if ($done >= $grandTotal) {
            return;
        }

        $elapsed = microtime(true) - $start;
        $rate = $done / max($elapsed, 0.001);
        $remaining = $rate > 0 ? ($grandTotal - $done) / $rate : 0;

        $style->text(sprintf(
            'Overall: %s/%s (%d%%) - %s docs/s - about %s remaining',
            number_format($done),
            number_format($grandTotal),
            (int) round($done / max($grandTotal, 1) * 100),
            number_format($rate),
            Helper::formatTime($remaining, 2)
        ));
        $style->newLine();
    }

    /**
     * @param array<int, array{shopId: int, langId: int}> $scopes
     */
    protected function buildDictionaries(array $scopes, SymfonyStyle $style): void
    {
        $style->section('Building correction dictionary');

        foreach ($scopes as $scope) {
            $count = $this->dictionaryBuilder->build($scope['shopId'], $scope['langId']);

            $style->text(sprintf(
                'Shop %d, language %d: %d terms',
                $scope['shopId'],
                $scope['langId'],
                $count
            ));
        }
    }

    protected function resolveWriter(InputInterface $input): IndexWriterInterface
    {
        $engine = $input->getOption('engine');

        return $engine === null
            ? $this->indexWriterLocator->getConfigured()
            : $this->indexWriterLocator->get((string) $engine);
    }

    /**
     * What the run will actually fill, for the line above the progress bars.
     *
     * Without --engine this is FOUN10EASYSEARCH_ENGINE - resolved and named rather
     * than printed as "configured", which told the operator nothing.
     *
     * The setting is read for the shop the console booted as, and **one run
     * fills one backend**: the writer holds the shadow tables of a swap and
     * cannot be exchanged between scopes. While all subshops are configured
     * for the same engine that is the same thing; while one is being migrated
     * it is not, and the label says which shop the value came from so the
     * difference is visible. Run per shop with --shop-id, or name --engine.
     */
    protected function resolveEngineName(InputInterface $input): string
    {
        $engine = $input->getOption('engine');

        if ($engine !== null) {
            return (string) $engine;
        }

        return sprintf(
            '%s (setting of shop %d, for the whole run)',
            $this->moduleSettings->getEngine(),
            $this->getCurrentShopId()
        );
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
            // Without --lang-id, only the languages the shop actually
            // serves. An explicit one is still honoured, so an inactive
            // language can be prepared before it goes live.
            $langIds = $langIdOption !== null
                ? [(int) $langIdOption]
                : $this->shopLanguages->getActiveIds($shopId);

            foreach ($langIds as $langId) {
                $scopes[] = ['shopId' => $shopId, 'langId' => $langId];
            }
        }

        return $scopes;
    }

    /*
     * The two shop touch points. Everything the run itself needs - which
     * scopes, which writer, how far it got - arrives through the constructor
     * or the options.
     */

    /**
     * @return int[]
     */
    protected function getAllShopIds(): array
    {
        return array_map('intval', (array) Registry::getConfig()->getShopIds());
    }

    /**
     * The shop the console booted as, which is the one whose engine setting
     * applies to the whole run.
     */
    protected function getCurrentShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }
}
