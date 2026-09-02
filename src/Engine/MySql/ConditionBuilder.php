<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\MySql;

use foun10\EasySearch\Correction\Normalizer;
use foun10\EasySearch\Engine\Query\FacetFilter;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Index\MySql\IndexTables;
use foun10\EasySearch\Synonym\SynonymExpander;
use foun10\EasySearch\Synonym\TermGroup;
use OxidEsales\Eshop\Core\Database\Adapter\DatabaseInterface;
use OxidEsales\Eshop\Core\DatabaseProvider;

/**
 * Translates a SearchQuery into SQL fragments.
 *
 * Three details here are worth knowing before changing anything:
 *
 * Short words. MariaDB silently drops any token shorter than
 * innodb_ft_min_token_size (3 by default) from a fulltext query. In a lingerie
 * catalogue that quietly breaks "BH" - the single most likely search term.
 * Tokens below the server's threshold therefore get a LIKE condition instead,
 * and the real value is read from the server rather than assumed.
 *
 * Operator characters. Boolean mode reads +, -, *, ~, ( and " as syntax, so a
 * customer typing "push-up +" would either error or silently search for
 * something else. They are stripped before the expression is built.
 *
 * Synonyms. The query is not a list of words but a list of TermGroups: every
 * group must match (AND), any alternative inside it will do (OR). A group whose
 * alternatives are all long enough for the fulltext index folds into the shared
 * boolean expression as "+(a* b*)". A group containing anything too short for
 * the index cannot - "bh" would be dropped from the expression and the OR would
 * quietly become "buestenhalter only" - so such a group gets its own condition
 * that ORs a MATCH against the LIKEs. Ranking ignores synonyms entirely; see
 * buildRelevance().
 */
class ConditionBuilder
{
    /**
     * Weight of a title/brand hit against a hit anywhere in the text. Three
     * turned out to be enough to keep exact title matches on top without
     * burying products whose description is the only place a term appears.
     */
    protected const BOOST_WEIGHT = 3;

    protected const FALLBACK_MIN_TOKEN_SIZE = 3;

    protected ?int $minTokenSize = null;

    public function __construct(
        protected Normalizer $normalizer,
        protected SynonymExpander $synonymExpander,
        protected IndexTables $tables
    ) {
    }

    /**
     * @param string|null $excludeAttributeId Facet whose own filter is left
     *                                        out, for its own hit counts
     */
    public function build(SearchQuery $query, ?string $excludeAttributeId = null): SearchConditions
    {
        $database = DatabaseProvider::getDb();
        // FOUN10VISIBLE already carries active plus the shop's stock rule,
        // decided once at index time - see VisibilityResolver.
        $conditions = ['i.OXSHOPID = :shopId', 'i.FOUN10LANGID = :langId', 'i.FOUN10VISIBLE = 1'];
        $parameters = [
            ':shopId' => $query->getShopId(),
            ':langId' => $query->getLangId(),
        ];

        $relevanceExpression = null;

        if ($query->hasTerm()) {
            $termConditions = $this->buildTermConditions($query, $parameters);

            if ($termConditions['where'] !== null) {
                $conditions[] = $termConditions['where'];
            }

            $relevanceExpression = $termConditions['relevance'];
        }

        if ($query->getCategoryId() !== null) {
            $conditions[] = $this->buildCategoryCondition(
                $query->getCategoryId(),
                $query->getShopId(),
                $database
            );
        }

        if ($query->getManufacturerId() !== null) {
            // A column on the index row rather than a join: an article has
            // exactly one manufacturer, so there is nothing to link.
            $conditions[] = 'i.FOUN10MANUFACTURERID = '
                . $database->quote($query->getManufacturerId());
        }

        if ($query->getPriceFrom() !== null) {
            $conditions[] = 'i.FOUN10PRICE >= :priceFrom';
            $parameters[':priceFrom'] = $query->getPriceFrom();
        }

        if ($query->getPriceTo() !== null) {
            $conditions[] = 'i.FOUN10PRICE <= :priceTo';
            $parameters[':priceTo'] = $query->getPriceTo();
        }

        foreach ($query->getFilters() as $filter) {
            if ($filter->isEmpty() || $filter->getAttributeId() === $excludeAttributeId) {
                continue;
            }

            $conditions[] = $this->buildFacetCondition($filter, $query->getShopId(), $database);
        }

        return new SearchConditions(
            implode(' AND ', $conditions),
            $parameters,
            $relevanceExpression
        );
    }

    /**
     * Narrows the result to one category.
     *
     * Matched on the group, not the variant: a category is assigned to the
     * product and every variant of it belongs to the same one. That is also why
     * this is the one filter that does not need to hold per variant.
     *
     * Which products a category contains is whatever oxobject2category says, so
     * subcategory roll-up behaves exactly as it does for the shop's own listing
     * - the index is derived from that table rather than reinterpreting it.
     */
    protected function buildCategoryCondition(
        string $categoryId,
        int $shopId,
        DatabaseInterface $database
    ): string {
        // OXSHOPID is named although the table holds one shop: its key starts
        // with that column, and an index whose first column is not constrained
        // cannot be used for the lookup - see FacetBuilder, where the same
        // omission cost a factor of five.
        return 'EXISTS (
            SELECT 1 FROM ' . $this->tables->category($shopId) . ' AS fc
            WHERE fc.OXSHOPID = ' . $shopId . '
                AND fc.FOUN10LANGID = i.FOUN10LANGID
                AND fc.FOUN10CATID = ' . $database->quote($categoryId) . '
                AND fc.FOUN10GROUPID = i.FOUN10GROUPID
        )';
    }

    /**
     * A variant has to satisfy every active facet itself. Checking against the
     * variant rather than the product is what stops a size 38 blouse from
     * showing up under "size 42" just because a sibling variant matches.
     */
    protected function buildFacetCondition(
        FacetFilter $filter,
        int $shopId,
        DatabaseInterface $database
    ): string {
        $quotedValues = implode(', ', $database->quoteArray($filter->getValueIds()));
        $quotedAttribute = $database->quote($filter->getAttributeId());

        return 'EXISTS (
            SELECT 1 FROM ' . $this->tables->attribute($shopId) . ' AS fa
            WHERE fa.FOUN10INDEXID = i.OXID
                AND fa.FOUN10ATTRID = ' . $quotedAttribute . '
                AND fa.FOUN10VALUEID IN (' . $quotedValues . ')
        )';
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{where: string|null, relevance: string|null}
     */
    protected function buildTermConditions(SearchQuery $query, array &$parameters): array
    {
        $tokens = $this->normalizer->tokenize(
            $this->normalizer->stripFulltextOperators($query->getTerm()),
            1
        );

        if ($tokens === []) {
            return ['where' => null, 'relevance' => null];
        }

        $groups = $this->synonymExpander->expand($tokens, $query->getShopId(), $query->getLangId());
        $database = DatabaseProvider::getDb();

        // Groups the shared boolean expression can carry, as "+a*" or "+(a* b*)"
        $required = [];
        $conditions = [];
        $groupIndex = 0;

        foreach ($groups as $group) {
            $split = $this->splitAlternatives($group);

            if ($split['like'] === []) {
                $required[] = count($split['fulltext']) === 1
                    ? '+' . $split['fulltext'][0]
                    : '+(' . implode(' ', $split['fulltext']) . ')';

                continue;
            }

            $alternatives = [];

            if ($split['fulltext'] !== []) {
                // Its own parameter rather than the shared one: this expression
                // is optional relative to the LIKEs beside it, and folding it
                // into the required expression would make it mandatory.
                $name = ':synonym' . $groupIndex++;
                $parameters[$name] = '(' . implode(' ', $split['fulltext']) . ')';

                $alternatives[] = 'MATCH (i.FOUN10EASYSEARCHTEXT) AGAINST (' . $name . ' IN BOOLEAN MODE)';
            }

            foreach ($split['like'] as $alternative) {
                $alternatives[] = $this->buildFallbackCondition($alternative, $database);
            }

            $conditions[] = count($alternatives) === 1
                ? $alternatives[0]
                : '(' . implode(' OR ', $alternatives) . ')';
        }

        if ($required !== []) {
            $parameters[':fulltext'] = implode(' ', $required);
            $conditions[] = 'MATCH (i.FOUN10EASYSEARCHTEXT) AGAINST (:fulltext IN BOOLEAN MODE)';
        }

        return [
            'where' => $conditions === [] ? null : '(' . implode(' AND ', $conditions) . ')',
            'relevance' => $this->buildRelevance($tokens, $database),
        ];
    }

    /**
     * Ranking expression, scored on the words the customer actually typed.
     *
     * Synonyms deliberately do not appear here. They decide what matches, never
     * how it ranks: a product whose title literally says "BH" is a better answer
     * to "bh" than one that only says "Büstenhalter", and scoring the synonym
     * side would push the literal hit underneath it. Rows found solely through a
     * synonym score zero on the term and fall to the bestseller tie break, which
     * is where a broadened match belongs.
     *
     * The side effect worth having is that adding or removing a synonym rule
     * cannot reorder anything that was already being found - the expression is
     * exactly the one this method produced before synonyms existed.
     *
     * Interpolated rather than bound, because it appears only in the ORDER BY:
     * the same parameter set is reused by the count query, which has no ORDER
     * BY and would be handed a parameter its SQL never mentions. Safe to quote
     * in, since every token came out of Normalizer::tokenize() and holds
     * letters and digits only.
     *
     * @param string[] $tokens
     */
    protected function buildRelevance(array $tokens, DatabaseInterface $database): ?string
    {
        $minTokenSize = $this->getMinTokenSize();
        $scoreable = [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) >= $minTokenSize) {
                $scoreable[] = '+' . $token . '*';
            }
        }

        if ($scoreable === []) {
            // Nothing the fulltext index can score - a query of short words
            // only. The caller orders by something else.
            return null;
        }

        $expression = $database->quote(implode(' ', $scoreable));

        return '('
            . self::BOOST_WEIGHT . ' * MATCH (i.FOUN10EASYSEARCHTEXTBOOST) AGAINST (' . $expression . ' IN BOOLEAN MODE)'
            . ' + MATCH (i.FOUN10EASYSEARCHTEXT) AGAINST (' . $expression . ' IN BOOLEAN MODE)'
            . ')';
    }

    /**
     * Sorts a group's alternatives into those the fulltext index can serve and
     * those that have to fall back to LIKE.
     *
     * @return array{fulltext: string[], like: array<int, string[]>}
     */
    protected function splitAlternatives(TermGroup $group): array
    {
        $minTokenSize = $this->getMinTokenSize();
        $fulltext = [];
        $like = [];

        foreach ($group->getAlternatives() as $alternative) {
            $indexable = true;

            foreach ($alternative as $word) {
                if (mb_strlen($word) < $minTokenSize) {
                    $indexable = false;
                    break;
                }
            }

            if ($indexable) {
                $fulltext[] = $this->toBooleanTerm($alternative);
                continue;
            }

            $like[] = $alternative;
        }

        return ['fulltext' => $fulltext, 'like' => $like];
    }

    /**
     * One alternative as a boolean mode operand.
     *
     * A single word matches as a prefix so "shirt" also finds "shirts". A multi
     * word alternative becomes a quoted phrase instead - "push up" has to match
     * the two words next to each other, or it would find every product that
     * merely mentions both somewhere.
     *
     * Safe to interpolate: every word came out of Normalizer::tokenize(), which
     * keeps letters and digits only, so no operator character can survive into
     * the expression.
     *
     * @param string[] $alternative
     */
    protected function toBooleanTerm(array $alternative): string
    {
        return count($alternative) === 1
            ? $alternative[0] . '*'
            : '"' . implode(' ', $alternative) . '"';
    }

    /**
     * Fallback for an alternative the fulltext index cannot serve as a phrase.
     *
     * Decided per word rather than for the alternative as a whole. Only the
     * words the index refuses to hold drop to LIKE against the boost text -
     * title, brand, article number, a varchar rather than the mediumtext - and
     * the rest keep their fulltext reach. Handling "push up" as one LIKE pair
     * would have quietly narrowed "push" from the whole search text down to the
     * title, losing products that only say it in their description.
     *
     * The words are required, not adjacent: an alternative that cannot go into
     * the index cannot express adjacency either. Its indexable counterparts do,
     * as a quoted phrase - each branch is as precise as its half of the data
     * allows, and the looser one is the branch that was already loose for short
     * terms before synonyms existed.
     *
     * Quoted in rather than bound for the same reason as the relevance
     * expression: these fragments are built per alternative, and every word
     * came out of Normalizer::tokenize() holding letters and digits only.
     *
     * @param string[] $alternative
     */
    protected function buildFallbackCondition(array $alternative, DatabaseInterface $database): string
    {
        $minTokenSize = $this->getMinTokenSize();
        $parts = [];

        foreach ($alternative as $word) {
            if (mb_strlen($word) >= $minTokenSize) {
                $parts[] = 'MATCH (i.FOUN10EASYSEARCHTEXT) AGAINST ('
                    . $database->quote('+' . $word . '*') . ' IN BOOLEAN MODE)';

                continue;
            }

            $parts[] = 'i.FOUN10EASYSEARCHTEXTBOOST LIKE ' . $database->quote('%' . $word . '%');
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(' AND ', $parts) . ')';
    }

    /**
     * Reads the server's fulltext minimum token size once per request.
     *
     * Asking the server matters: the value is a startup variable, so it can
     * differ between the local container and production, and guessing wrong
     * means short terms silently return nothing.
     */
    protected function getMinTokenSize(): int
    {
        if ($this->minTokenSize !== null) {
            return $this->minTokenSize;
        }

        try {
            $value = (int) DatabaseProvider::getDb()->getOne('SELECT @@innodb_ft_min_token_size');
        } catch (\Throwable $exception) {
            $value = 0;
        }

        $this->minTokenSize = $value > 0 ? $value : self::FALLBACK_MIN_TOKEN_SIZE;

        return $this->minTokenSize;
    }
}
