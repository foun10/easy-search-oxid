<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Correction;

use foun10\EasySearch\Correction\CorrectedTerm;
use PHPUnit\Framework\TestCase;

/**
 * What the corrector hands back.
 *
 * hasChanged() is what the caller asks before showing "showing results for ..."
 * to a customer. Answering true when nothing actually changed puts a confusing
 * notice on a perfectly ordinary search.
 */
class CorrectedTermTest extends TestCase
{
    public function testItCarriesBothSpellingsAndTheEffort(): void
    {
        $term = new CorrectedTerm('nachtemd', 'nachthemd', 1, 1);

        $this->assertSame('nachtemd', $term->getOriginal());
        $this->assertSame('nachthemd', $term->getCorrected());
        $this->assertSame(1, $term->getMaxDistance());
        $this->assertSame(1, $term->getCorrectedTokenCount());
        $this->assertTrue($term->hasChanged());
    }

    /**
     * Both halves of the rule matter, and each fails differently.
     *
     * @dataProvider unchangedProvider
     */
    public function testNothingChangedIsReportedHonestly(
        string $original,
        string $corrected,
        int $count,
        string $why
    ): void {
        $this->assertFalse((new CorrectedTerm($original, $corrected, 1, $count))->hasChanged(), $why);
    }

    public function unchangedProvider(): array
    {
        return [
            'same text, no tokens touched' => ['bh spitze', 'bh spitze', 0, 'nothing was corrected'],
            'no tokens touched'            => ['bh', 'bhs', 0, 'the count says nothing was replaced'],
            'text identical'               => ['bh', 'bh', 1, 'a replacement that produced the same word'],
        ];
    }

    public function testAMultiWordPhraseReportsHowManyWordsMoved(): void
    {
        $term = new CorrectedTerm('nachtemd blusse', 'nachthemd bluse', 1, 2);

        $this->assertTrue($term->hasChanged());
        $this->assertSame(2, $term->getCorrectedTokenCount());
        $this->assertSame(1, $term->getMaxDistance(), 'the worst single edit, not the sum');
    }

    public function testAnEmptyOriginalIsStillComparable(): void
    {
        $this->assertFalse((new CorrectedTerm('', '', 0, 0))->hasChanged());
    }
}
