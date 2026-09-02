<?php
declare(strict_types=1);

namespace foun10\EasySearch\Synonym;

use foun10\EasySearch\Core\SynonymConfiguration;
use foun10\EasySearch\Correction\Normalizer;

/**
 * Turns the words a customer typed into the groups the engine has to satisfy.
 *
 * Expansion happens at query time, not at index time. Writing synonyms into the
 * indexed text would be faster to query, but every edit would then need a full
 * reindex before it took effect - and a merchant adding "bh = buestenhalter"
 * expects to see it work on the next search, not after a nightly rebuild.
 * Rule sets are a few dozen rows, so the lookup costs nothing worth saving.
 *
 * Matching is longest first. With rules on both "push up" and "bh", the query
 * "push up bh" has to consume the two word phrase as one group rather than
 * matching "push" on its own and leaving "up" behind.
 *
 * Everything is compared in the Normalizer's folded form, which is what makes a
 * merchant's "Buestenhalter" match a customer's "Bustenhalter" without either
 * of them having to think about it.
 */
class SynonymExpander
{
    /**
     * Longest trigger phrase considered, in words.
     *
     * Bounds the lookahead per query position. Longer rules are ignored rather
     * than silently half matched - a five word trigger is not a synonym, it is
     * a redirect, and belongs in a different feature.
     */
    protected const MAX_PHRASE_WORDS = 4;

    /**
     * Compiled rule sets per scope: phrase lookup, longest key, known words.
     *
     * @var array<string, array{phrases: array<string, array<int, string[]>>, maxWords: int, words: array<string, bool>}>
     */
    protected array $compiled = [];

    public function __construct(
        protected SynonymConfiguration $configuration,
        protected Normalizer $normalizer
    ) {
    }

    /**
     * @param string[] $tokens Normalised query words, in the order typed
     *
     * @return TermGroup[]
     */
    public function expand(array $tokens, int $shopId, int $langId): array
    {
        $tokens = array_values($tokens);
        $compiled = $this->compile($shopId, $langId);
        $groups = [];
        $count = count($tokens);
        $position = 0;

        while ($position < $count) {
            $group = $this->matchAt($tokens, $position, $count, $compiled);

            $groups[] = $group;
            $position += $group->getLength();
        }

        return $groups;
    }

    /**
     * Whether a word appears anywhere in the configured rules of a scope.
     *
     * The spell corrector asks before correcting: a merchant who configured
     * "bralette" has declared it a real word, and correcting it away to
     * whatever the catalogue happens to contain would defeat the very rule that
     * was written to catch that search.
     */
    public function isKnownWord(string $word, int $shopId, int $langId): bool
    {
        $normalized = $this->normalizer->normalize($word);

        return isset($this->compile($shopId, $langId)['words'][$normalized]);
    }

    /**
     * Longest phrase starting at $position, or the bare word if nothing matches.
     *
     * @param string[] $tokens
     * @param array{phrases: array<string, array<int, string[]>>, maxWords: int, words: array<string, bool>} $compiled
     */
    protected function matchAt(array $tokens, int $position, int $count, array $compiled): TermGroup
    {
        $length = min($compiled['maxWords'], $count - $position);

        for (; $length >= 1; $length--) {
            $words = array_slice($tokens, $position, $length);
            $key = implode(' ', $words);

            if (isset($compiled['phrases'][$key])) {
                return new TermGroup($words, $compiled['phrases'][$key]);
            }
        }

        return TermGroup::plain($tokens[$position]);
    }

    /**
     * Builds the lookup for one scope.
     *
     * A two way rule becomes an entry under every one of its phrases, all
     * pointing at the same set. A one way rule becomes a single entry under its
     * term, so the synonyms stay reachable from the term and not the reverse.
     *
     * @return array{phrases: array<string, array<int, string[]>>, maxWords: int, words: array<string, bool>}
     */
    protected function compile(int $shopId, int $langId): array
    {
        $key = $shopId . '_' . $langId;

        if (isset($this->compiled[$key])) {
            return $this->compiled[$key];
        }

        $phrases = [];
        $words = [];
        $maxWords = 1;

        foreach ($this->configuration->getActiveRules($shopId, $langId) as $rule) {
            $term = $this->toPhrase($rule->getTerm());

            if ($term === [] || count($term) > self::MAX_PHRASE_WORDS) {
                continue;
            }

            $alternatives = $this->collectPhrases($term, $rule);

            // Nothing survived normalisation except the term itself - the rule
            // says a word is a synonym of itself, which is not a rule.
            if (count($alternatives) < 2) {
                continue;
            }

            foreach ($rule->isTwoWay() ? $alternatives : [$term] as $trigger) {
                $phraseKey = implode(' ', $trigger);
                $maxWords = max($maxWords, count($trigger));

                // Two rules may name the same trigger; the customer should get
                // the reach of both rather than whichever was saved last.
                $phrases[$phraseKey] = $this->unique(array_merge(
                    $phrases[$phraseKey] ?? [$trigger],
                    $alternatives
                ));
            }

            foreach ($alternatives as $alternative) {
                foreach ($alternative as $word) {
                    $words[$word] = true;
                }
            }
        }

        return $this->compiled[$key] = [
            'phrases' => $phrases,
            'maxWords' => $maxWords,
            'words' => $words,
        ];
    }

    /**
     * The term plus its synonyms as normalised word lists, term first and
     * duplicates removed.
     *
     * @param string[] $term
     *
     * @return array<int, string[]>
     */
    protected function collectPhrases(array $term, SynonymRule $rule): array
    {
        $phrases = [$term];

        foreach ($rule->getSynonymList() as $synonym) {
            $phrase = $this->toPhrase($synonym);

            if ($phrase === [] || count($phrase) > self::MAX_PHRASE_WORDS) {
                continue;
            }

            $phrases[] = $phrase;
        }

        return $this->unique($phrases);
    }

    /**
     * @param array<int, string[]> $phrases
     *
     * @return array<int, string[]>
     */
    protected function unique(array $phrases): array
    {
        $seen = [];
        $unique = [];

        foreach ($phrases as $phrase) {
            $key = implode(' ', $phrase);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $phrase;
        }

        return $unique;
    }

    /**
     * Normalises one configured entry into the word list the query path uses.
     *
     * Minimum length 1, matching how the condition builder tokenises the query
     * itself: dropping short words here would turn "push up" into "push" on one
     * side only, and the two would stop lining up.
     *
     * @return string[]
     */
    protected function toPhrase(string $value): array
    {
        return $this->normalizer->tokenize($value, 1);
    }
}
