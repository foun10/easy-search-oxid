<?php
declare(strict_types=1);

namespace foun10\EasySearch\Log;

/**
 * Decides whether an input is a search term at all.
 *
 * The search box is a public form field, so it collects everything a scanner
 * throws at a shop: SQL fragments, script tags, traversal paths, template
 * expressions, whole URLs. None of that is a customer telling you what they
 * wanted, and all of it drowns out the lists that are.
 *
 * Two reasons to drop it rather than to merely hide it later:
 *
 *  - a report that has to be read past its own noise does not get read;
 *  - the raw term is stored to be shown back in the backend, and the shortest
 *    way to never render an attack payload is to never store it. Escaping in
 *    the template is what actually stops it - see the template, which escapes
 *    everything - but the payload has no business in the database either way.
 *
 * The heuristics are deliberately blunt, and they run on the **raw** input:
 * normalisation throws away exactly the characters that give an attack away.
 * A false positive costs one row in a statistic; a customer's search is never
 * affected, because nothing here touches the query path.
 */
class TermFilter
{
    /**
     * Longer than this is not somebody looking for a product.
     *
     * The column holds 255 and the logger cuts to that. Real terms are far
     * shorter - the longest sensible German product phrase in this catalogue is
     * around 40 characters.
     */
    protected const MAX_LENGTH = 80;

    /**
     * Above this share of punctuation a term stops looking like words.
     *
     * Only applied from a few characters up: "3/4" and "36c" are real searches
     * in a lingerie shop, and both would fail a ratio test on their own.
     */
    protected const SYMBOL_RATIO = 0.4;
    protected const SYMBOL_MIN_LENGTH = 12;

    public const REASON_CONTROL = 'control';
    public const REASON_MARKUP = 'markup';
    public const REASON_SQL = 'sql';
    public const REASON_PATH = 'path';
    public const REASON_TEMPLATE = 'template';
    public const REASON_SHELL = 'shell';
    public const REASON_URL = 'url';
    public const REASON_LENGTH = 'length';
    public const REASON_SYMBOLS = 'symbols';

    /**
     * Reason => patterns, matched case insensitively against the raw term.
     *
     * @var array<string, string[]>
     */
    protected const PATTERNS = [
        self::REASON_MARKUP => [
            '/<\s*[a-z!\/?]/i',
            '/javascript\s*:/i',
            '/\bon(?:error|load|click|mouse\w+)\s*=/i',
            '/&#x?[0-9a-f]{2,};/i',
        ],
        self::REASON_SQL => [
            '/\bunion\b[\s\S]{0,20}\bselect\b/i',
            '/\bselect\b[\s\S]{0,40}\bfrom\b/i',
            '/\b(?:insert\s+into|update\s+\w+\s+set|delete\s+from|drop\s+table)\b/i',
            '/\binformation_schema\b/i',
            '/\b(?:sleep|benchmark|extractvalue|updatexml|load_file)\s*\(/i',
            '/(?:\'|")\s*(?:or|and)\s+(?:\d+\s*=\s*\d+|\'[^\']*\'\s*=)/i',
            '/(?:--|#|\/\*)\s*$/',
        ],
        self::REASON_PATH => [
            '/\.\.[\/\\\\]/',
            '/\/(?:etc|proc)\/[a-z]/i',
            '/\b(?:php|file|data|expect):\/\//i',
        ],
        self::REASON_TEMPLATE => [
            '/\{\{.*\}\}/',
            '/\$\{.*\}/',
            '/<%.*%>/',
            '/#\{.*\}/',
        ],
        self::REASON_SHELL => [
            '/\$\(.*\)/',
            '/`[^`]+`/',
            '/[|;&]\s*(?:curl|wget|nc|bash|sh|python|powershell)\b/i',
        ],
        self::REASON_URL => [
            '/\bhttps?:\/\//i',
            '/\bwww\.[a-z0-9-]+\.[a-z]{2,}/i',
        ],
    ];

    /**
     * Why this input is not a search term, or null if it is one.
     *
     * A reason rather than a bare bool, so the backend can say what it dropped
     * instead of quietly having fewer rows than the counter promises.
     */
    public function check(string $term): ?string
    {
        $term = trim($term);

        if ($term === '') {
            return null;
        }

        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $term) === 1) {
            return self::REASON_CONTROL;
        }

        if (mb_strlen($term) > self::MAX_LENGTH) {
            return self::REASON_LENGTH;
        }

        foreach (self::PATTERNS as $reason => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $term) === 1) {
                    return $reason;
                }
            }
        }

        if ($this->isMostlySymbols($term)) {
            return self::REASON_SYMBOLS;
        }

        return null;
    }

    public function isSuspicious(string $term): bool
    {
        return $this->check($term) !== null;
    }

    /**
     * A term made mostly of punctuation, which the patterns above miss when the
     * payload is obfuscated or simply unfamiliar.
     */
    protected function isMostlySymbols(string $term): bool
    {
        $length = mb_strlen($term);

        if ($length < self::SYMBOL_MIN_LENGTH) {
            return false;
        }

        $words = preg_replace('/[^\p{L}\p{N}\s]/u', '', $term) ?? '';

        return ($length - mb_strlen($words)) / $length > self::SYMBOL_RATIO;
    }
}
