<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Command;

use foun10\EasySearch\Command\SearchLogCommand;
use foun10\EasySearch\Log\Period;
use foun10\EasySearch\Log\SearchLog;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The search report on the console.
 *
 * The command is a thin layer over SearchLog, so what is worth pinning is not
 * the SQL - that is the reader's job, and the integration suite's - but the
 * decisions the command makes on top of it: which scope it asks about when
 * nobody says, which of the two lists it draws, and how it behaves when the
 * log has nothing to say or cannot be read at all.
 *
 * The last one matters more than it looks. The log table arrives through a
 * migration, so "the migration has not run" is the most likely state this
 * command meets on a fresh installation, and a stack trace would send the
 * operator looking in the wrong place.
 */
class SearchLogCommandTest extends TestCase
{
    /** @var SearchLog&MockObject */
    private SearchLog $searchLog;

    protected function setUp(): void
    {
        $this->searchLog = $this->createMock(SearchLog::class);
    }

    /**
     * SymfonyStyle wraps at the terminal width, so a sentence in the output is
     * not a sentence in the string. Collapsing the whitespace puts it back
     * together and keeps the assertions readable.
     */
    private function display(CommandTester $tester): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new SearchLogCommand($this->searchLog));
    }

    /**
     * @param array<string, int> $overrides
     *
     * @return array{searches: int, terms: int, zeroSearches: int, zeroTerms: int}
     */
    private function summary(array $overrides = []): array
    {
        return $overrides + [
            'searches' => 1000,
            'terms' => 120,
            'zeroSearches' => 250,
            'zeroTerms' => 40,
        ];
    }

    /**
     * @param array{searches: int, terms: int, zeroSearches: int, zeroTerms: int}|null $summary
     */
    private function expectSummary(?array $summary = null): void
    {
        $this->searchLog->method('getSummary')->willReturn($summary ?? $this->summary());
    }

    /**
     * Records what the reader was asked for, which is the only place the
     * resolved scope becomes visible.
     *
     * @param array<string, mixed> $seen
     */
    private function captureScope(array &$seen): void
    {
        $this->searchLog
            ->method('getSummary')
            ->willReturnCallback(function (int $shopId, int $langId, Period $period) use (&$seen): array {
                $seen = ['shopId' => $shopId, 'langId' => $langId, 'period' => $period];

                return $this->summary();
            });
    }

    public function testTheSummaryLineCountsSearchesTermsAndTheShareThatFoundNothing(): void
    {
        $this->expectSummary();

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertStringContainsString(
            '1,000 searches over 120 distinct terms. 250 of them found nothing (25.0%), across 40 terms.',
            $this->display($tester)
        );
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * Nobody names a scope from cron, so the default is the one that has to be
     * right: the first shop, its base language, and a month back.
     */
    public function testWithoutOptionsItReportsOnShopOneLanguageZeroAndThirtyDays(): void
    {
        $seen = [];
        $this->captureScope($seen);

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(1, $seen['shopId']);
        $this->assertSame(0, $seen['langId']);
        $this->assertSame(date('Y-m-d'), $seen['period']->getTo());
        $this->assertSame(
            date('Y-m-d', strtotime('-29 days')),
            $seen['period']->getFrom(),
            'thirty days counted inclusively reaches 29 days back, not 30'
        );
        $this->assertStringContainsString('Searches in shop 1, language 0, last 30 days', $this->display($tester));
    }

    public function testTheOptionsNarrowTheScopeAndTheHeadlineSaysSo(): void
    {
        $seen = [];
        $this->captureScope($seen);

        $this->searchLog
            ->expects($this->once())
            ->method('getTopTerms')
            ->with(2, 1, $this->isInstanceOf(Period::class), 5)
            ->willReturn([]);

        $this->searchLog
            ->expects($this->once())
            ->method('getZeroHitTerms')
            ->with(2, 1, $this->isInstanceOf(Period::class), 5)
            ->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--shop-id' => '2', '--lang-id' => '1', '--days' => '7', '--limit' => '5']);

        $this->assertSame(2, $seen['shopId']);
        $this->assertSame(1, $seen['langId']);
        $this->assertSame(date('Y-m-d', strtotime('-6 days')), $seen['period']->getFrom());
        $this->assertStringContainsString('Searches in shop 2, language 1, last 7 days', $this->display($tester));
    }

    /**
     * A zero would otherwise ask for an empty period and a list of no rows,
     * which reads as "nothing was searched" rather than "you asked for nothing".
     */
    public function testDaysAndLimitNeverFallBelowOne(): void
    {
        $seen = [];
        $this->captureScope($seen);

        $this->searchLog
            ->expects($this->once())
            ->method('getTopTerms')
            ->with(1, 0, $this->isInstanceOf(Period::class), 1)
            ->willReturn([]);

        $this->searchLog->method('getZeroHitTerms')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--days' => '0', '--limit' => '0']);

        $this->assertSame(date('Y-m-d'), $seen['period']->getFrom(), 'a single day starts and ends today');
        $this->assertSame(date('Y-m-d'), $seen['period']->getTo());
        $this->assertStringContainsString('last 1 days', $this->display($tester));
    }

    /**
     * The state a fresh installation is in until the migration has run. The
     * exception says "table not found", which is true and useless; the command
     * says what to do about it.
     */
    public function testAnUnreadableLogPointsAtTheMigrationInsteadOfFailingRaw(): void
    {
        $this->searchLog
            ->method('getSummary')
            ->willThrowException(new RuntimeException('Table foun10easysearchlog does not exist'));

        $this->searchLog->expects($this->never())->method('getTopTerms');
        $this->searchLog->expects($this->never())->method('getZeroHitTerms');

        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));

        $this->assertStringContainsString(
            'Could not read the search log: Table foun10easysearchlog does not exist'
            . ' - has the migration run?',
            $this->display($tester),
            'the cause first, then what to do about it'
        );
    }

    /**
     * An installation where logging is on but nobody has searched yet. Two
     * empty tables would suggest the report is broken; one sentence does not.
     */
    public function testAnEmptyLogStopsAfterOneSentenceAndSucceeds(): void
    {
        $this->expectSummary($this->summary(['searches' => 0, 'terms' => 0, 'zeroSearches' => 0, 'zeroTerms' => 0]));

        $this->searchLog->expects($this->never())->method('getTopTerms');
        $this->searchLog->expects($this->never())->method('getZeroHitTerms');

        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Nothing logged yet for this scope.', $this->display($tester));
    }

    public function testZeroOnlyDropsTheMostSearchedListAndKeepsTheOtherOne(): void
    {
        $this->expectSummary();

        $this->searchLog->expects($this->never())->method('getTopTerms');
        $this->searchLog
            ->expects($this->once())
            ->method('getZeroHitTerms')
            ->willReturn([
                ['term' => 'gummistiefel', 'searches' => 12, 'lastSeen' => '2026-08-30', 'corrected' => 'gummistiefe'],
            ]);

        $tester = $this->tester();
        $tester->execute(['--zero-only' => true]);

        $display = $this->display($tester);
        $this->assertStringNotContainsString('Most searched', $display);
        $this->assertStringContainsString('Found nothing', $display);
        $this->assertStringContainsString('gummistiefel', $display);
    }

    public function testBothListsAreDrawnWithTheirCountsGrouped(): void
    {
        $this->expectSummary();

        $this->searchLog->method('getTopTerms')->willReturn([
            ['term' => 'jacke', 'searches' => 1234, 'hits' => 56789, 'lastSeen' => '2026-08-31'],
        ]);
        $this->searchLog->method('getZeroHitTerms')->willReturn([
            ['term' => 'gummistiefel', 'searches' => 2345, 'lastSeen' => '2026-08-30', 'corrected' => 'gummistiefe'],
        ]);

        $tester = $this->tester();
        $tester->execute([]);

        $display = $this->display($tester);
        $this->assertStringContainsString('Most searched', $display);
        $this->assertStringContainsString('term searches hits last seen', $display, 'the header row');
        $this->assertStringContainsString(
            'term searches last seen correction offered',
            $display,
            'and the one of the second list'
        );
        $this->assertStringContainsString('jacke', $display);
        $this->assertStringContainsString('1,234', $display);
        $this->assertStringContainsString('56,789', $display);
        $this->assertStringContainsString('2026-08-31', $display);
        $this->assertStringContainsString('Found nothing', $display);
        $this->assertStringContainsString('gummistiefel', $display);
        $this->assertStringContainsString('2,345', $display);
        $this->assertStringContainsString('gummistiefe', $display);
    }

    /**
     * The advice line is the point of the second list, so it must not stand
     * under an empty one - there is nothing there to write a synonym rule for.
     */
    public function testAnEmptyZeroHitListSaysSoAndDropsTheAdvice(): void
    {
        $this->expectSummary();

        $this->searchLog->method('getTopTerms')->willReturn([]);
        $this->searchLog->method('getZeroHitTerms')->willReturn([]);

        $tester = $this->tester();
        $tester->execute([]);

        $display = $this->display($tester);
        $this->assertStringContainsString('Every logged term found something.', $display);
        $this->assertStringNotContainsString('synonym rule waiting to be written', $display);
    }

    public function testANonEmptyZeroHitListCarriesTheAdvice(): void
    {
        $this->expectSummary();

        $this->searchLog->method('getTopTerms')->willReturn([]);
        $this->searchLog->method('getZeroHitTerms')->willReturn([
            ['term' => 'gummistiefel', 'searches' => 12, 'lastSeen' => '2026-08-30', 'corrected' => ''],
        ]);

        $tester = $this->tester();
        $tester->execute([]);

        $display = $this->display($tester);
        $this->assertStringContainsString('synonym rule waiting to be written', $display);
        $this->assertStringNotContainsString('Every logged term found something.', $display);
    }

    public function testTheCommandIsNamedForTheConsoleAndDeclaresItsOptions(): void
    {
        $command = new SearchLogCommand($this->searchLog);

        $this->assertSame('foun10:easysearch:log', $command->getName());

        $definition = $command->getDefinition();

        foreach (['shop-id', 'lang-id', 'days', 'limit', 'zero-only'] as $option) {
            $this->assertTrue($definition->hasOption($option), $option . ' is missing');
        }

        // The OXID console defines --shop-id globally and Symfony only tolerates
        // a redefinition that matches exactly, which a default would break.
        $this->assertNull($definition->getOption('shop-id')->getDefault());
        $this->assertNull($definition->getOption('lang-id')->getDefault());
        $this->assertSame('30', $definition->getOption('days')->getDefault());
        $this->assertSame('25', $definition->getOption('limit')->getDefault());
        $this->assertFalse($definition->getOption('zero-only')->acceptValue());
    }
}
