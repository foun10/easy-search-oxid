<?php
declare(strict_types=1);

namespace foun10\EasySearch\Correction;

use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Synonym\SynonymExpander;
use OxidEsales\Eshop\Core\DatabaseProvider;

/**
 * Turns a misspelled query into the closest term the catalogue actually
 * contains.
 *
 * The work happens in three steps, and the order matters for performance:
 *
 *  1. Tokens that exist verbatim in the dictionary are left alone. Most
 *     queries are spelled correctly and never reach the expensive part.
 *  2. Candidates are pre-filtered in SQL by leading-character bucket plus a
 *     length window, or by Cologne phonetic code. This turns tens of thousands
 *     of dictionary terms into a few hundred rows.
 *  3. Only those candidates get a Damerau-Levenshtein distance calculation in
 *     PHP, with early termination once a candidate is provably too far.
 *
 * Correcting towards a rare term is dangerous - product data contains its own
 * typos - so candidates below FOUN10EASYSEARCH_CORRECTION_MIN_FREQUENCY are
 * ignored, and ties are broken by catalogue frequency.
 */
class SpellCorrector
{
    /**
     * Upper bound on rows pulled per token. Generous enough that the right
     * candidate is virtually always inside, small enough to stay cheap.
     */
    protected const CANDIDATE_LIMIT = 400;

    public function __construct(
        protected Normalizer $normalizer,
        protected DamerauLevenshtein $damerauLevenshtein,
        protected ColognePhonetic $colognePhonetic,
        protected ModuleSettings $moduleSettings,
        protected SynonymExpander $synonymExpander
    ) {
    }

    /**
     * Corrects a whole search phrase token by token.
     *
     * Returns null when nothing could or should be corrected, so the caller
     * can keep the original query untouched.
     */
    public function correct(string $term, int $shopId, int $langId): ?CorrectedTerm
    {
        if (!$this->moduleSettings->isCorrectionEnabled()) {
            return null;
        }

        $tokens = $this->normalizer->tokenize($term, $this->moduleSettings->getMinTermLength());

        if ($tokens === []) {
            return null;
        }

        $correctedTokens = [];
        $maxDistance = 0;
        $correctedCount = 0;

        foreach ($tokens as $token) {
            $candidate = $this->correctToken($token, $shopId, $langId);

            if ($candidate === null) {
                $correctedTokens[] = $token;
                continue;
            }

            $correctedTokens[] = $candidate['term'];
            $maxDistance = max($maxDistance, $candidate['distance']);
            $correctedCount++;
        }

        if ($correctedCount === 0) {
            return null;
        }

        return new CorrectedTerm(
            implode(' ', $tokens),
            implode(' ', $correctedTokens),
            $maxDistance,
            $correctedCount
        );
    }

    /**
     * @return array{term: string, distance: int}|null
     */
    protected function correctToken(string $token, int $shopId, int $langId): ?array
    {
        $length = mb_strlen($token);
        $maxDistance = $this->damerauLevenshtein->getMaxDistanceForLength($length);

        // Too short to correct safely - a single edit would change the word
        // into an unrelated one.
        if ($maxDistance === 0) {
            return null;
        }

        if ($this->existsInDictionary($token, $shopId, $langId)) {
            return null;
        }

        // A word somebody configured a synonym rule for is a real word by
        // decision, even when the catalogue never spells it out. Correcting it
        // away would defeat the rule written to catch exactly that search:
        // "bralette" would silently become whatever the dictionary has nearby,
        // and the synonym would never fire.
        if ($this->synonymExpander->isKnownWord($token, $shopId, $langId)) {
            return null;
        }

        $candidates = $this->fetchCandidates($token, $shopId, $langId, $maxDistance);

        if ($candidates === []) {
            return null;
        }

        $best = null;

        foreach ($candidates as $candidate) {
            $distance = $this->damerauLevenshtein->distance(
                $token,
                $candidate['FOUN10TERM'],
                $maxDistance
            );

            if ($distance > $maxDistance) {
                continue;
            }

            $frequency = (int) $candidate['FOUN10FREQUENCY'];

            // Closer wins; at equal distance the more common term wins.
            if (
                $best === null
                || $distance < $best['distance']
                || ($distance === $best['distance'] && $frequency > $best['frequency'])
            ) {
                $best = [
                    'term' => (string) $candidate['FOUN10TERM'],
                    'distance' => $distance,
                    'frequency' => $frequency,
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        return ['term' => $best['term'], 'distance' => $best['distance']];
    }

    protected function existsInDictionary(string $token, int $shopId, int $langId): bool
    {
        $sql = "
            SELECT 1
            FROM foun10easysearchdictionary
            WHERE OXSHOPID = :shopId
                AND FOUN10LANGID = :langId
                AND FOUN10TERM = :term
            LIMIT 1
        ";

        return (bool) DatabaseProvider::getDb()->getOne($sql, [
            ':shopId' => $shopId,
            ':langId' => $langId,
            ':term' => $token,
        ]);
    }

    /**
     * Pre-filters dictionary rows down to a plausible candidate set.
     *
     * The bucket branch catches typos from the third character onwards, the
     * phonetic branch catches the rest - including a typo in the first two
     * characters, where the bucket no longer matches.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchCandidates(string $token, int $shopId, int $langId, int $maxDistance): array
    {
        $length = mb_strlen($token);

        $parameters = [
            ':shopId' => $shopId,
            ':langId' => $langId,
            ':minFrequency' => $this->moduleSettings->getCorrectionMinFrequency(),
            ':bucket' => $this->normalizer->getBucket($token),
            ':phonetic' => $this->colognePhonetic->encode($token),
            ':lengthFrom' => max(1, $length - $maxDistance),
            ':lengthTo' => $length + $maxDistance,
        ];

        $sql = "
            SELECT FOUN10TERM, FOUN10FREQUENCY
            FROM foun10easysearchdictionary
            WHERE OXSHOPID = :shopId
                AND FOUN10LANGID = :langId
                AND FOUN10FREQUENCY >= :minFrequency
                AND FOUN10LENGTH BETWEEN :lengthFrom AND :lengthTo
                AND (FOUN10BUCKET = :bucket OR FOUN10PHONETIC = :phonetic)
            ORDER BY FOUN10FREQUENCY DESC
            LIMIT " . self::CANDIDATE_LIMIT . "
        ";

        return DatabaseHelper::fetchAll($sql, $parameters);
    }
}
