<?php
declare(strict_types=1);

namespace foun10\EasySearch\Synonym;

/**
 * One position in the customer's query, together with everything that may
 * satisfy it.
 *
 * A query is a list of these, and the engine has to satisfy every group - the
 * alternatives inside a group are alternatives (OR), the groups themselves are
 * all required (AND). "roter bh" with bh ↔ büstenhalter is two groups: "roter",
 * and "bh OR büstenhalter".
 *
 * A phrase is a word list rather than a string because a synonym may be several
 * words ("push up"), and how those words become a condition is the engine's
 * decision, not this class's.
 */
class TermGroup
{
    /**
     * @param string[]              $words        The query words this group consumed
     * @param array<int, string[]>  $alternatives Word lists, the source phrase first
     */
    public function __construct(
        protected readonly array $words,
        protected readonly array $alternatives
    ) {
    }

    /**
     * Group for a word nothing was configured for.
     */
    public static function plain(string $word): self
    {
        return new self([$word], [[$word]]);
    }

    /**
     * @return string[]
     */
    public function getWords(): array
    {
        return $this->words;
    }

    /**
     * @return array<int, string[]>
     */
    public function getAlternatives(): array
    {
        return $this->alternatives;
    }

    /**
     * How many query words this group swallowed - the caller advances by this.
     */
    public function getLength(): int
    {
        return count($this->words);
    }

    public function isExpanded(): bool
    {
        return count($this->alternatives) > 1;
    }
}
