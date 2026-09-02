<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Core;

use foun10\EasySearch\Tests\Unit\Double\TestableShopLanguages;
use PHPUnit\Framework\TestCase;

/**
 * Which languages a shop actually serves.
 *
 * The class exists because OXID makes this easy to get wrong: getLanguageIds()
 * and getActiveShopLanguageIds() both return every *configured* language,
 * active or not - despite the second being named for the opposite. Only
 * getLanguageArray(null, true) honours the active flag, and it answers for
 * whichever shop happens to be in context.
 *
 * Getting it wrong is quiet: the admin offers a label field for a language
 * nobody can reach, and a rebuild spends minutes indexing a catalogue no
 * customer will ever search. So the rules are pinned here.
 */
class ShopLanguagesTest extends TestCase
{
    /**
     * @return array{id: int, abbr: string, name: string}
     */
    private function entry(int $id, string $abbr, string $name): array
    {
        return ['id' => $id, 'abbr' => $abbr, 'name' => $name];
    }

    public function testTheCurrentShopIsAnsweredFromTheLanguageContext(): void
    {
        $languages = new TestableShopLanguages(currentShopId: 1);
        $languages->contextLanguages = [$this->entry(0, 'de', 'Deutsch'), $this->entry(1, 'en', 'English')];

        $this->assertSame(
            [$this->entry(0, 'de', 'Deutsch'), $this->entry(1, 'en', 'English')],
            $languages->getActive()
        );
        $this->assertSame(1, $languages->contextCalls);
        $this->assertSame([], $languages->foreignCalls, 'no need to read a configuration');
    }

    /**
     * Naming the current shop explicitly is the same thing as not naming one.
     */
    public function testNamingTheCurrentShopStillUsesTheContext(): void
    {
        $languages = new TestableShopLanguages(currentShopId: 3);
        $languages->contextLanguages = [$this->entry(0, 'de', 'Deutsch')];

        $languages->getActive(3);

        $this->assertSame(1, $languages->contextCalls);
        $this->assertSame([], $languages->foreignCalls);
    }

    /**
     * The case the shop id parameter exists for: the console walks every
     * subshop, and getLanguageArray() would answer for whichever one happens to
     * be in context rather than the one being indexed.
     */
    public function testAnotherShopIsReadFromItsOwnConfiguration(): void
    {
        $languages = new TestableShopLanguages(currentShopId: 1);
        $languages->contextLanguages = [$this->entry(0, 'de', 'Deutsch')];
        $languages->foreignLanguages[2] = [$this->entry(0, 'fr', 'Francais')];

        $this->assertSame([$this->entry(0, 'fr', 'Francais')], $languages->getActive(2));
        $this->assertSame(0, $languages->contextCalls, 'the context would have been the wrong shop');
        $this->assertSame([2], $languages->foreignCalls);
    }

    /**
     * A shop with nothing flagged active would otherwise offer nothing to index
     * and nothing to configure, which is never what is meant.
     */
    public function testAShopWithNoActiveLanguageFallsBackToASingleDefault(): void
    {
        $languages = new TestableShopLanguages();
        $languages->contextLanguages = [];

        $this->assertSame([$this->entry(0, 'de', 'de')], $languages->getActive());
    }

    public function testTheFallbackAlsoAppliesToAnotherShop(): void
    {
        $languages = new TestableShopLanguages(currentShopId: 1);
        $languages->foreignLanguages[4] = [];

        $this->assertSame([$this->entry(0, 'de', 'de')], $languages->getActive(4));
    }

    public function testTheIdsAreExtractedInOrder(): void
    {
        $languages = new TestableShopLanguages();
        $languages->contextLanguages = [
            $this->entry(0, 'de', 'Deutsch'),
            $this->entry(2, 'fr', 'Francais'),
            $this->entry(1, 'en', 'English'),
        ];

        $this->assertSame([0, 2, 1], $languages->getActiveIds());
    }

    public function testTheFallbackLanguageIsReportedAsActive(): void
    {
        $languages = new TestableShopLanguages();
        $languages->contextLanguages = [];

        $this->assertSame([0], $languages->getActiveIds());
        $this->assertTrue($languages->isActive(0));
    }

    /**
     * The question the admin asks before offering a label field, and the
     * indexer before spending minutes on a language.
     */
    public function testActivityIsDecidedByIdNotByPosition(): void
    {
        $languages = new TestableShopLanguages();
        $languages->contextLanguages = [$this->entry(0, 'de', 'Deutsch'), $this->entry(2, 'fr', 'Francais')];

        $this->assertTrue($languages->isActive(0));
        $this->assertTrue($languages->isActive(2));
        $this->assertFalse($languages->isActive(1), 'configured but not switched on');
        $this->assertFalse($languages->isActive(99));
    }

    public function testActivityCanBeAskedForAnotherShop(): void
    {
        $languages = new TestableShopLanguages(currentShopId: 1);
        $languages->contextLanguages = [$this->entry(0, 'de', 'Deutsch')];
        $languages->foreignLanguages[2] = [$this->entry(5, 'it', 'Italiano')];

        $this->assertTrue($languages->isActive(5, 2));
        $this->assertFalse($languages->isActive(0, 2), 'shop 2 does not serve language 0');
        $this->assertTrue($languages->isActive(0), 'while the current shop does');
    }
}
