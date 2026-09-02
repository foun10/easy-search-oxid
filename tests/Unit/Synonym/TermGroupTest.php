<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Synonym;

use foun10\EasySearch\Synonym\TermGroup;
use PHPUnit\Framework\TestCase;

/**
 * One consumed slice of the query, together with the phrasings that may stand
 * in for it.
 *
 * getLength() is the part with teeth: the expander advances through the query
 * by it, so a wrong length either skips a word the customer typed or reads one
 * twice.
 */
class TermGroupTest extends TestCase
{
    public function testAPlainGroupIsAWordThatStandsForItself(): void
    {
        $group = TermGroup::plain('spitze');

        $this->assertSame(['spitze'], $group->getWords());
        $this->assertSame([['spitze']], $group->getAlternatives());
        $this->assertFalse($group->isExpanded());
    }

    public function testAPlainGroupConsumesExactlyOneWord(): void
    {
        $this->assertSame(1, TermGroup::plain('spitze')->getLength());
    }

    /**
     * A synonym may be several words, which is why alternatives are word lists
     * rather than strings - how they become a condition is the engine's call.
     */
    public function testAlternativesAreWordListsSoMultiWordSynonymsSurvive(): void
    {
        $group = new TermGroup(['bh'], [['bh'], ['push', 'up']]);

        $this->assertSame([['bh'], ['push', 'up']], $group->getAlternatives());
        $this->assertTrue($group->isExpanded());
    }

    /**
     * The length is what the caller advances by, so a group that swallowed a
     * two word phrase must say two - not one, and not the number of
     * alternatives it offers.
     */
    public function testTheLengthCountsConsumedQueryWordsNotAlternatives(): void
    {
        $group = new TermGroup(['bügel', 'bh'], [['bügel', 'bh'], ['buegelbh'], ['bh']]);

        $this->assertSame(2, $group->getLength());
        $this->assertCount(3, $group->getAlternatives());
    }

    /**
     * A group is expanded only when something other than the source phrase is
     * on offer. One alternative means the word stands for itself.
     */
    public function testAGroupWithOnlyItsSourcePhraseIsNotExpanded(): void
    {
        $this->assertFalse((new TermGroup(['bh'], [['bh']]))->isExpanded());
        $this->assertTrue((new TermGroup(['bh'], [['bh'], ['büstenhalter']]))->isExpanded());
    }

    public function testAnEmptyGroupConsumesNothing(): void
    {
        $group = new TermGroup([], []);

        $this->assertSame(0, $group->getLength());
        $this->assertFalse($group->isExpanded());
    }
}
