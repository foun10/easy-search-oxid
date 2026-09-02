<?php
declare(strict_types=1);

namespace foun10\EasySearch\Correction;

/**
 * Outcome of correcting one search phrase.
 *
 * Carries the worst distance of all corrected tokens so the caller can decide
 * how confidently to present the result: a phrase fixed by a single
 * transposition is safe to apply silently, one that needed two edits on two
 * separate words is better offered as a question.
 */
class CorrectedTerm
{
    public function __construct(
        protected readonly string $original,
        protected readonly string $corrected,
        protected readonly int $maxDistance,
        protected readonly int $correctedTokenCount
    ) {
    }

    public function getOriginal(): string
    {
        return $this->original;
    }

    public function getCorrected(): string
    {
        return $this->corrected;
    }

    public function getMaxDistance(): int
    {
        return $this->maxDistance;
    }

    public function getCorrectedTokenCount(): int
    {
        return $this->correctedTokenCount;
    }

    public function hasChanged(): bool
    {
        return $this->correctedTokenCount > 0 && $this->corrected !== $this->original;
    }
}
