<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Correction\SpellCorrector;

/**
 * SpellCorrector with its two data-access methods replaced.
 *
 * The class reaches the database in exactly two places, both of them protected:
 * existsInDictionary() and fetchCandidates(). Everything else - the length
 * thresholds, the synonym veto, the candidate ranking, the phrase assembly - is
 * ordinary logic that only needed those two doors closed to become testable
 * without a shop.
 *
 * Overriding them here rather than standing up a database is what keeps the
 * correction rules provable at all: the ranking cares about ties between terms
 * of equal distance and different frequency, and constructing that situation in
 * a real dictionary is far harder than stating it.
 */
class TestableSpellCorrector extends SpellCorrector
{
    /** @var string[] Terms the dictionary is to report as known */
    public array $knownTerms = [];

    /** @var array<int, array{FOUN10TERM: string, FOUN10FREQUENCY: int}> */
    public array $candidates = [];

    /** @var array<int, array{token: string, shopId: int, langId: int, maxDistance: int}> */
    public array $candidateCalls = [];

    protected function existsInDictionary(string $token, int $shopId, int $langId): bool
    {
        return in_array($token, $this->knownTerms, true);
    }

    protected function fetchCandidates(string $token, int $shopId, int $langId, int $maxDistance): array
    {
        $this->candidateCalls[] = [
            'token' => $token,
            'shopId' => $shopId,
            'langId' => $langId,
            'maxDistance' => $maxDistance,
        ];

        return $this->candidates;
    }
}
