<?php
declare(strict_types=1);

namespace foun10\EasySearch\Correction;

/**
 * Turns raw input into the canonical form used everywhere in the index,
 * the dictionary and the query path.
 *
 * The same normalisation has to run at index time and at query time, or
 * lookups silently miss. Keep this class free of engine specifics so a future
 * Meilisearch writer can reuse it unchanged.
 *
 * German folding is deliberate: umlauts are expanded (ue, oe, ae) rather than
 * stripped, so "Buegelhalter" and "Bügelhalter" collapse onto the same token
 * and a customer typing either spelling lands on the same products.
 */
class Normalizer
{
    /**
     * Expanded before the generic accent folding, otherwise "ü" would become
     * a bare "u" and "buegel"/"bügel" would no longer match.
     */
    protected const UMLAUT_MAP = [
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'Ä' => 'ae',
        'Ö' => 'oe',
        'Ü' => 'ue',
        'ß' => 'ss',
    ];

    protected const ACCENT_MAP = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    /**
     * Dropped from the dictionary and from search terms. Short and hand
     * picked - an aggressive stopword list hurts a fashion catalogue, where
     * "in" can be part of a real product name.
     */
    protected const STOPWORDS = [
        'der', 'die', 'das', 'den', 'dem', 'des',
        'ein', 'eine', 'einen', 'einem', 'einer', 'eines',
        'und', 'oder', 'mit', 'ohne', 'fuer', 'von', 'vom',
        'the', 'and', 'for', 'with',
    ];

    /**
     * Full normalisation of a single string: lowercase, umlaut folding,
     * accent folding, punctuation to spaces, whitespace collapsed.
     */
    public function normalize(string $value): string
    {
        $value = strtr($value, self::UMLAUT_MAP);
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, self::ACCENT_MAP);

        // Keep digits and letters, everything else becomes a separator. Hyphens
        // in "Push-Up" must not glue the parts together, so they become spaces.
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * Normalises and splits into tokens, dropping stopwords and single
     * characters.
     *
     * @return string[]
     */
    public function tokenize(string $value, int $minLength = 2): array
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return [];
        }

        $tokens = explode(' ', $normalized);

        $tokens = array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token) >= $minLength
                && !in_array($token, self::STOPWORDS, true)
        );

        return array_values(array_unique($tokens));
    }

    /**
     * Bucket key used to narrow dictionary candidates before the distance
     * calculation. Two leading characters keep the candidate set small while
     * still tolerating a typo further into the word.
     */
    public function getBucket(string $normalizedTerm): string
    {
        return mb_substr($normalizedTerm, 0, 2);
    }

    public function isStopword(string $normalizedTerm): bool
    {
        return in_array($normalizedTerm, self::STOPWORDS, true);
    }

    /**
     * Strips characters that carry meaning in MySql boolean fulltext mode.
     * Without this a customer typing "bh +" would produce a syntax error or,
     * worse, a query with unintended operator semantics.
     */
    public function stripFulltextOperators(string $value): string
    {
        return str_replace(
            ['+', '-', '<', '>', '(', ')', '~', '*', '"', '@'],
            ' ',
            $value
        );
    }
}
