<?php
declare(strict_types=1);

namespace foun10\EasySearch\Correction;

/**
 * Optimal string alignment distance (restricted Damerau-Levenshtein).
 *
 * Why not PHP's built-in levenshtein():
 *
 *  - It counts a transposition as two edits, so "shrit" -> "shirt" comes out
 *    as distance 2 and is indistinguishable from a genuinely different word.
 *    Transposing two adjacent characters is the single most common typo, and
 *    this class scores it as 1.
 *  - It works on bytes, so any multibyte input (umlauts, even after folding
 *    an unexpected character survives) produces nonsense distances.
 *  - It is capped at 255 bytes.
 *
 * "Restricted" means no substring is edited more than once, which is the
 * standard trade-off: it is materially cheaper than unrestricted Damerau and
 * the difference only shows on inputs far beyond any threshold we accept.
 */
class DamerauLevenshtein
{
    /**
     * Distance between two already normalised terms.
     *
     * $maxDistance lets the calculation bail out early: while scanning a few
     * hundred dictionary candidates, most are obviously too far away and
     * there is no point completing their matrix.
     *
     * @return int The distance, or $maxDistance + 1 if it provably exceeds it
     */
    public function distance(string $source, string $target, ?int $maxDistance = null): int
    {
        if ($source === $target) {
            return 0;
        }

        $sourceChars = $this->split($source);
        $targetChars = $this->split($target);
        $sourceLength = count($sourceChars);
        $targetLength = count($targetChars);

        if ($sourceLength === 0) {
            return $targetLength;
        }

        if ($targetLength === 0) {
            return $sourceLength;
        }

        // A length difference alone already exceeds the budget.
        if ($maxDistance !== null && abs($sourceLength - $targetLength) > $maxDistance) {
            return $maxDistance + 1;
        }

        $previousRow = [];
        $currentRow = [];
        $beforePreviousRow = [];

        for ($j = 0; $j <= $targetLength; $j++) {
            $previousRow[$j] = $j;
        }

        for ($i = 1; $i <= $sourceLength; $i++) {
            $currentRow = [0 => $i];
            $rowMinimum = $i;

            for ($j = 1; $j <= $targetLength; $j++) {
                $cost = $sourceChars[$i - 1] === $targetChars[$j - 1] ? 0 : 1;

                $value = min(
                    $currentRow[$j - 1] + 1,      // insertion
                    $previousRow[$j] + 1,         // deletion
                    $previousRow[$j - 1] + $cost  // substitution
                );

                // Transposition of two adjacent characters.
                if (
                    $i > 1
                    && $j > 1
                    && $sourceChars[$i - 1] === $targetChars[$j - 2]
                    && $sourceChars[$i - 2] === $targetChars[$j - 1]
                ) {
                    $value = min($value, $beforePreviousRow[$j - 2] + 1);
                }

                $currentRow[$j] = $value;

                if ($value < $rowMinimum) {
                    $rowMinimum = $value;
                }
            }

            // Every remaining row can only add to the distance, so once the
            // best value in this row is over budget we can stop.
            if ($maxDistance !== null && $rowMinimum > $maxDistance) {
                return $maxDistance + 1;
            }

            $beforePreviousRow = $previousRow;
            $previousRow = $currentRow;
        }

        return $previousRow[$targetLength];
    }

    /**
     * Distance threshold accepted for a term of the given length.
     *
     * Short words carry little redundancy - allowing an edit on a four letter
     * word turns "rot" into "rock". The steps mirror what Typesense and
     * Meilisearch use by default, which keeps behaviour familiar if we ever
     * move the correction step into the engine itself.
     */
    public function getMaxDistanceForLength(int $length): int
    {
        if ($length <= 3) {
            return 0;
        }

        if ($length <= 6) {
            return 1;
        }

        return 2;
    }

    /**
     * @return string[]
     */
    protected function split(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
