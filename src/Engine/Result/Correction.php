<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Result;

/**
 * Outcome of the typo tolerance step.
 *
 * Two modes, and the distinction matters for the customer:
 *
 *  - APPLIED: the original term had (almost) no hits, the corrected one has
 *    clearly more. The corrected term was searched and the page shows
 *    "Showing results for shirt - search instead for shrit".
 *  - SUGGESTED: the original term had usable hits, so its results are shown
 *    unchanged and the correction is only offered as "Did you mean ...?".
 *
 * Never silently replace a term that produced good hits.
 */
class Correction
{
    public const MODE_APPLIED = 'applied';
    public const MODE_SUGGESTED = 'suggested';

    public function __construct(
        protected readonly string $original,
        protected readonly string $corrected,
        protected readonly string $mode = self::MODE_APPLIED,
        protected readonly int $distance = 0,
        protected readonly int $correctedHits = 0
    ) {
    }

    public static function applied(
        string $original,
        string $corrected,
        int $distance,
        int $correctedHits
    ): static {
        return new static($original, $corrected, self::MODE_APPLIED, $distance, $correctedHits);
    }

    public static function suggested(
        string $original,
        string $corrected,
        int $distance,
        int $correctedHits
    ): static {
        return new static($original, $corrected, self::MODE_SUGGESTED, $distance, $correctedHits);
    }

    public function getOriginal(): string
    {
        return $this->original;
    }

    public function getCorrected(): string
    {
        return $this->corrected;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isApplied(): bool
    {
        return $this->mode === self::MODE_APPLIED;
    }

    /**
     * Damerau-Levenshtein distance between the original and corrected term.
     * Transposition counts as one edit, which is why "shrit" -> "shirt" is
     * distance 1 and not 2 as PHP's built-in levenshtein() would report.
     */
    public function getDistance(): int
    {
        return $this->distance;
    }

    public function getCorrectedHits(): int
    {
        return $this->correctedHits;
    }
}
