<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Correction;

use foun10\EasySearch\Correction\DamerauLevenshtein;
use PHPUnit\Framework\TestCase;

/**
 * The distance decides which word a misspelling is corrected towards. Too
 * generous and "rot" becomes "rock"; too strict and an obvious typo is left
 * alone. Both failures are silent, so the thresholds are pinned here.
 */
class DamerauLevenshteinTest extends TestCase
{
    private DamerauLevenshtein $distance;

    protected function setUp(): void
    {
        $this->distance = new DamerauLevenshtein();
    }

    public function testIdenticalTermsHaveNoDistance(): void
    {
        $this->assertSame(0, $this->distance->distance('spitze', 'spitze'));
    }

    public function testAnEmptySideCostsTheLengthOfTheOther(): void
    {
        $this->assertSame(3, $this->distance->distance('', 'bh1'));
        $this->assertSame(6, $this->distance->distance('spitze', ''));
        $this->assertSame(0, $this->distance->distance('', ''));
    }

    /**
     * @dataProvider editProvider
     */
    public function testSingleEdits(string $source, string $target, int $expected): void
    {
        $this->assertSame($expected, $this->distance->distance($source, $target));
    }

    public function editProvider(): array
    {
        return [
            'substitution' => ['rot', 'rat', 1],
            'insertion'    => ['nachtemd', 'nachthemd', 1],
            'deletion'     => ['spitzen', 'spitze', 1],
            'two edits'    => ['spitze', 'splitz', 2],
        ];
    }

    /**
     * The Damerau part. Plain Levenshtein charges two edits for swapped
     * neighbours, which is the single most common way to mistype a word - and
     * exactly the case a search box has to forgive.
     */
    public function testATranspositionCostsOneEditNotTwo(): void
    {
        $this->assertSame(1, $this->distance->distance('ab', 'ba'));
        $this->assertSame(1, $this->distance->distance('spitze', 'sptize'));
    }

    /**
     * While scanning dictionary candidates most are obviously too far away.
     * The budget lets the matrix stop early, and the contract is that it
     * returns "over budget" rather than the true distance.
     */
    public function testABudgetIsAnsweredWithOverBudgetRatherThanTheRealDistance(): void
    {
        $this->assertSame(3, $this->distance->distance('a', 'abcdef', 2), 'length difference alone busts the budget');
        $this->assertSame(2, $this->distance->distance('rot', 'rock', 2), 'inside the budget, the real distance is returned');
    }

    public function testABudgetNeverHidesAnExactMatch(): void
    {
        $this->assertSame(0, $this->distance->distance('bh', 'bh', 0));
    }

    /**
     * Short words carry little redundancy: one edit turns "rot" into "rock",
     * so nothing under four characters may be corrected at all.
     *
     * @dataProvider thresholdProvider
     */
    public function testTheAcceptedThresholdGrowsWithWordLength(int $length, int $expected): void
    {
        $this->assertSame($expected, $this->distance->getMaxDistanceForLength($length));
    }

    public function thresholdProvider(): array
    {
        return [
            'empty'     => [0, 0],
            'three'     => [3, 0],
            'four'      => [4, 1],
            'six'       => [6, 1],
            'seven'     => [7, 2],
            'very long' => [40, 2],
        ];
    }
}
