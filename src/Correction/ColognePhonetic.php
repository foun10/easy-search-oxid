<?php
declare(strict_types=1);

namespace foun10\EasySearch\Correction;

/**
 * Koelner Phonetik (Cologne phonetics).
 *
 * Deliberately not soundex() or metaphone(): both are tuned to English
 * phonetics and mangle German. Cologne phonetics is the German-language
 * equivalent and is what makes "Maier"/"Meyer"/"Mayer" or "Schals"/"Schaals"
 * collapse onto one code.
 *
 * Used as a second candidate source next to edit distance. It catches
 * misspellings that sound right but look far apart - where edit distance
 * alone would rate them too distant to offer.
 *
 * Expects input that already went through the Normalizer, i.e. lowercase with
 * umlauts folded to ae/oe/ue.
 */
class ColognePhonetic
{
    /**
     * Characters that make a preceding C sound like "k" rather than "z".
     */
    protected const C_HARD_FOLLOWERS = ['a', 'h', 'k', 'o', 'q', 'u', 'x'];

    /**
     * Same, but for a C at the very start of the word - the set is wider.
     */
    protected const C_HARD_FOLLOWERS_INITIAL = ['a', 'h', 'k', 'l', 'o', 'q', 'r', 'u', 'x'];

    /**
     * Characters after which a C is always coded as 8.
     */
    protected const C_SIBILANT_PREDECESSORS = ['s', 'z'];

    public function encode(string $normalizedTerm): string
    {
        $chars = preg_split('//u', $normalizedTerm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $length = count($chars);
        $codes = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $chars[$i];
            $previous = $i > 0 ? $chars[$i - 1] : null;
            $next = $i + 1 < $length ? $chars[$i + 1] : null;

            $codes .= $this->encodeChar($char, $previous, $next, $i === 0);
        }

        return $this->compact($codes);
    }

    protected function encodeChar(string $char, ?string $previous, ?string $next, bool $isFirst): string
    {
        switch ($char) {
            case 'a':
            case 'e':
            case 'i':
            case 'j':
            case 'o':
            case 'u':
            case 'y':
                return '0';

            case 'h':
                // Carries no code of its own, only modifies P.
                return '';

            case 'b':
                return '1';

            case 'p':
                return $next === 'h' ? '3' : '1';

            case 'd':
            case 't':
                return in_array($next, ['c', 's', 'z'], true) ? '8' : '2';

            case 'f':
            case 'v':
            case 'w':
                return '3';

            case 'g':
            case 'k':
            case 'q':
                return '4';

            case 'c':
                return $this->encodeC($previous, $next, $isFirst);

            case 'x':
                // Already covered by a preceding k sound, otherwise it is the
                // full "ks" pair.
                return in_array($previous, ['c', 'k', 'q'], true) ? '8' : '48';

            case 'l':
                return '5';

            case 'm':
            case 'n':
                return '6';

            case 'r':
                return '7';

            case 's':
            case 'z':
                return '8';

            default:
                // Digits and anything else contribute nothing.
                return '';
        }
    }

    protected function encodeC(?string $previous, ?string $next, bool $isFirst): string
    {
        if ($isFirst) {
            return in_array($next, self::C_HARD_FOLLOWERS_INITIAL, true) ? '4' : '8';
        }

        if (in_array($previous, self::C_SIBILANT_PREDECESSORS, true)) {
            return '8';
        }

        return in_array($next, self::C_HARD_FOLLOWERS, true) ? '4' : '8';
    }

    /**
     * Collapses repeated digits, then drops every 0 except a leading one.
     */
    protected function compact(string $codes): string
    {
        if ($codes === '') {
            return '';
        }

        $result = '';
        $previous = null;

        foreach (str_split($codes) as $code) {
            if ($code !== $previous) {
                $result .= $code;
            }

            $previous = $code;
        }

        $first = substr($result, 0, 1);
        $rest = str_replace('0', '', substr($result, 1));

        return $first . $rest;
    }
}
