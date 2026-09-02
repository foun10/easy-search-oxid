<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Synonym;

use foun10\EasySearch\Synonym\SynonymRule;
use PHPUnit\Framework\TestCase;

/**
 * One editorial synonym rule, as the backend screen stores it.
 *
 * Rules are applied at query time, so a broken one changes what every customer
 * sees on the next search rather than after the next reindex - and it does so
 * silently, by returning more or fewer products than intended.
 */
class SynonymRuleTest extends TestCase
{
    public function testTheStoredTypeValuesAreStable(): void
    {
        $this->assertSame('both', SynonymRule::TYPE_BOTH, 'this string is in the database');
        $this->assertSame('oneway', SynonymRule::TYPE_ONEWAY);
        $this->assertSame(['both', 'oneway'], SynonymRule::TYPES);
    }

    public function testATwoWayRuleIsRecognised(): void
    {
        $rule = new SynonymRule(SynonymRule::TYPE_BOTH, 'bh', 'büstenhalter');

        $this->assertSame(SynonymRule::TYPE_BOTH, $rule->getType());
        $this->assertTrue($rule->isTwoWay());
    }

    /**
     * The narrower direction: "bralette" may reach triangle bras without
     * somebody searching "triangel-bh" getting bralettes back.
     */
    public function testAOneWayRuleOnlyBroadensInOneDirection(): void
    {
        $rule = new SynonymRule(SynonymRule::TYPE_ONEWAY, 'bralette', 'triangel-bh');

        $this->assertSame(SynonymRule::TYPE_ONEWAY, $rule->getType());
        $this->assertFalse($rule->isTwoWay());
    }

    /**
     * A type that is not one of the two - a typo, or a value left over from an
     * older version - has to mean something. Two-way is the safe reading: it
     * broadens the search rather than silently narrowing it.
     *
     * @dataProvider unknownTypeProvider
     */
    public function testAnUnknownTypeFallsBackToTwoWay(string $type): void
    {
        $rule = new SynonymRule($type, 'bh', 'büstenhalter');

        $this->assertSame(SynonymRule::TYPE_BOTH, $rule->getType());
        $this->assertTrue($rule->isTwoWay());
    }

    public function unknownTypeProvider(): array
    {
        return [
            'empty'     => [''],
            'typo'      => ['onway'],
            'wrong case' => ['BOTH'],
            'retired'   => ['bidirectional'],
        ];
    }

    /**
     * Comma is the only separator, deliberately: a synonym may contain a space
     * ("push up"), and splitting on whitespace would tear it in half.
     */
    public function testMultiWordSynonymsSurviveTheSplit(): void
    {
        $rule = new SynonymRule(SynonymRule::TYPE_BOTH, 'bh', 'push up, bügel bh');

        $this->assertSame(['push up', 'bügel bh'], $rule->getSynonymList());
    }

    public function testTheSynonymListIsTrimmedDeduplicatedAndCompacted(): void
    {
        $rule = new SynonymRule(SynonymRule::TYPE_BOTH, 'bh', '  spitze , , bh ,spitze,  ');

        $this->assertSame(['spitze', 'bh'], $rule->getSynonymList());
    }

    public function testTheSynonymListIsAListNotAGappedArray(): void
    {
        $rule = new SynonymRule(SynonymRule::TYPE_BOTH, 'bh', 'a,,b');

        $this->assertSame([0, 1], array_keys($rule->getSynonymList()));
    }

    public function testTheRawSynonymStringIsKeptForTheEditingScreen(): void
    {
        $rule = new SynonymRule(SynonymRule::TYPE_BOTH, 'bh', '  spitze , bh ');

        $this->assertSame('  spitze , bh ', $rule->getSynonyms(), 'the screen edits the raw string');
        $this->assertSame('bh', $rule->getTerm());
    }

    /**
     * Half a rule expands nothing, and an incomplete one that still got applied
     * would either broaden into an empty set or match everything.
     *
     * @dataProvider incompleteProvider
     */
    public function testARuleNeedsBothSidesToBeComplete(string $term, string $synonyms): void
    {
        $this->assertFalse((new SynonymRule(SynonymRule::TYPE_BOTH, $term, $synonyms))->isComplete());
    }

    public function incompleteProvider(): array
    {
        return [
            'no term'          => ['', 'spitze'],
            'blank term'       => ['   ', 'spitze'],
            'no synonyms'      => ['bh', ''],
            'only separators'  => ['bh', ' , , '],
            'neither'          => ['', ''],
        ];
    }

    public function testACompleteRuleHasBothSides(): void
    {
        $this->assertTrue((new SynonymRule(SynonymRule::TYPE_BOTH, 'bh', 'büstenhalter'))->isComplete());
    }

    public function testARuleIsActiveUnlessSaidOtherwise(): void
    {
        $this->assertTrue((new SynonymRule(SynonymRule::TYPE_BOTH, 'bh', 'x'))->isActive());
        $this->assertFalse((new SynonymRule(SynonymRule::TYPE_BOTH, 'bh', 'x', false))->isActive());
    }
}
