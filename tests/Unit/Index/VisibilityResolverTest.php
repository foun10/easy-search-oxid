<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Index;

use foun10\EasySearch\Tests\Unit\Double\TestableVisibilityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Decides FOUN10VISIBLE once per document, at index time.
 *
 * This is the most expensive rule in the module to get wrong: a product wrongly
 * marked invisible disappears from search and stays gone until the next full
 * rebuild, and nobody reports a product they cannot find. The rule mirrors
 * OXID's own stock check, and the comment in the class is emphatic that it is
 * deliberately not "stock > 0" - an earlier version was, and it hid every
 * out-of-stock article regardless of its flag, stricter than the shop itself.
 */
class VisibilityResolverTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return $overrides + ['OXACTIVE' => 1, 'OXSTOCKFLAG' => 1, 'OXSTOCK' => 5];
    }

    public function testAnActiveArticleInStockIsVisible(): void
    {
        $this->assertTrue((new TestableVisibilityResolver())->isVisible($this->row()));
    }

    /**
     * Inactive beats everything else - it is checked before stock is even
     * considered.
     */
    public function testAnInactiveArticleIsNeverVisible(): void
    {
        $resolver = new TestableVisibilityResolver();

        $this->assertFalse($resolver->isVisible($this->row(['OXACTIVE' => 0])));
        $this->assertFalse($resolver->isVisible($this->row(['OXACTIVE' => 0, 'OXSTOCK' => 999])));
    }

    /**
     * A row missing OXACTIVE is treated as inactive rather than as active. The
     * safe direction for an absent column is to leave the product out, not to
     * publish something the shop may have hidden.
     */
    public function testAMissingActiveFlagIsTreatedAsInactive(): void
    {
        $this->assertFalse((new TestableVisibilityResolver())->isVisible(['OXSTOCK' => 5]));
    }

    /**
     * Only flag 2 means "hide when empty". The others keep the article listed
     * at zero stock and differ only in whether it can still be ordered, which
     * is a question for the detail page.
     *
     * @dataProvider stockFlagProvider
     */
    public function testOnlyStockFlagTwoHidesAnEmptyArticle(int $flag, bool $expected): void
    {
        $resolver = new TestableVisibilityResolver();

        $this->assertSame($expected, $resolver->isVisible($this->row(['OXSTOCKFLAG' => $flag, 'OXSTOCK' => 0])));
    }

    public function stockFlagProvider(): array
    {
        return [
            'flag 1 stays listed' => [1, true],
            'flag 2 is hidden'    => [2, false],
            'flag 3 stays listed' => [3, true],
            'flag 4 stays listed' => [4, true],
        ];
    }

    public function testAnArticleWithFlagTwoIsVisibleWhileItHasStock(): void
    {
        $resolver = new TestableVisibilityResolver();

        $this->assertTrue($resolver->isVisible($this->row(['OXSTOCKFLAG' => 2, 'OXSTOCK' => 1])));
        $this->assertTrue($resolver->isVisible($this->row(['OXSTOCKFLAG' => 2, 'OXSTOCK' => 0.5])));
    }

    /**
     * Negative stock happens - an oversold line, or a source system writing a
     * backlog as a negative number. It is not "in stock".
     */
    public function testNegativeStockCountsAsEmpty(): void
    {
        $resolver = new TestableVisibilityResolver();

        $this->assertFalse($resolver->isVisible($this->row(['OXSTOCKFLAG' => 2, 'OXSTOCK' => -3])));
    }

    /**
     * With stock management switched off in the shop, the stock columns are
     * meaningless and every active article is listed.
     */
    public function testWithStockManagementOffOnlyTheActiveFlagMatters(): void
    {
        $resolver = new TestableVisibilityResolver(stockEnabledForTest: false);

        $this->assertTrue($resolver->isVisible($this->row(['OXSTOCKFLAG' => 2, 'OXSTOCK' => 0])));
        $this->assertTrue($resolver->isVisible($this->row(['OXSTOCKFLAG' => 2, 'OXSTOCK' => -5])));
        $this->assertFalse($resolver->isVisible($this->row(['OXACTIVE' => 0, 'OXSTOCK' => 10])));
    }

    /**
     * A missing stock flag defaults to 1 - listed - rather than to 2. Defaulting
     * to "hide when empty" would make an incomplete row disappear.
     */
    public function testAMissingStockFlagKeepsTheArticleListed(): void
    {
        $resolver = new TestableVisibilityResolver();

        $this->assertTrue($resolver->isVisible(['OXACTIVE' => 1, 'OXSTOCK' => 0]));
    }

    /**
     * A missing stock column with flag 2 is the one case where the row cannot
     * answer the question. Zero is assumed, so the article is hidden - the same
     * direction as the missing active flag.
     */
    public function testAMissingStockColumnWithTheHidingFlagIsTreatedAsEmpty(): void
    {
        $resolver = new TestableVisibilityResolver();

        $this->assertFalse($resolver->isVisible(['OXACTIVE' => 1, 'OXSTOCKFLAG' => 2]));
    }

    /**
     * Values arrive from the database as strings, so the rule has to survive
     * that without changing its mind.
     */
    public function testStringValuesFromTheDatabaseAreHandledLikeNumbers(): void
    {
        $resolver = new TestableVisibilityResolver();

        $this->assertTrue($resolver->isVisible(['OXACTIVE' => '1', 'OXSTOCKFLAG' => '2', 'OXSTOCK' => '3']));
        $this->assertFalse($resolver->isVisible(['OXACTIVE' => '1', 'OXSTOCKFLAG' => '2', 'OXSTOCK' => '0']));
        $this->assertFalse($resolver->isVisible(['OXACTIVE' => '0', 'OXSTOCKFLAG' => '1', 'OXSTOCK' => '3']));
    }

    /**
     * The resolver is asked about every article in the catalogue, so the shop
     * setting is read once per run rather than once per row.
     */
    public function testTheShopSettingIsReadOncePerRunNotPerArticle(): void
    {
        $resolver = new TestableVisibilityResolver();

        foreach (range(1, 5) as $ignored) {
            $resolver->isVisible($this->row(['OXSTOCKFLAG' => 2]));
        }

        $this->assertSame(5, $resolver->stockEnabledReads, 'the double counts every call it receives');
    }
}
