<?php
declare(strict_types=1);

namespace foun10\EasySearch\Synonym;

/**
 * One configured synonym rule, exactly as the merchant typed it.
 *
 * Nothing here is normalised. Folding "Büstenhalter" to "buestenhalter" is the
 * query path's job and happens in SynonymExpander, so the admin screen can
 * always show the words back the way they were entered.
 */
class SynonymRule
{
    /**
     * Every word of the rule is equivalent to every other: whichever one a
     * customer types, all of them are searched.
     */
    public const TYPE_BOTH = 'both';

    /**
     * Only the term broadens into its synonyms, never the other way round.
     *
     * The case this exists for is a narrower word that should reach a wider
     * assortment without dragging that whole assortment back: "bralette" may
     * legitimately also mean triangle bras, while somebody searching
     * "triangel-bh" is not asking for bralettes.
     */
    public const TYPE_ONEWAY = 'oneway';

    public const TYPES = [self::TYPE_BOTH, self::TYPE_ONEWAY];

    public function __construct(
        protected readonly string $type,
        protected readonly string $term,
        protected readonly string $synonyms,
        protected readonly bool $active = true
    ) {
    }

    public function getType(): string
    {
        return in_array($this->type, self::TYPES, true) ? $this->type : self::TYPE_BOTH;
    }

    public function isTwoWay(): bool
    {
        return $this->getType() === self::TYPE_BOTH;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    /**
     * The synonyms as one comma separated string, the way the screen edits it.
     */
    public function getSynonyms(): string
    {
        return $this->synonyms;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * The synonym side split into single entries.
     *
     * Comma is the only separator: a synonym may well contain a space
     * ("push up"), so splitting on whitespace would tear multi word entries
     * apart.
     *
     * @return string[]
     */
    public function getSynonymList(): array
    {
        $entries = array_map('trim', explode(',', $this->synonyms));

        return array_values(array_unique(array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== ''
        )));
    }

    /**
     * A rule needs both sides to mean anything.
     */
    public function isComplete(): bool
    {
        return trim($this->term) !== '' && $this->getSynonymList() !== [];
    }
}
