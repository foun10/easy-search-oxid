<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\MySql;

/**
 * Assembled SQL fragments for one query, shared by the result query and every
 * facet count query so both can never drift apart.
 *
 * Internal to the MySql engine - nothing outside this namespace should depend
 * on it, because a different backend has no equivalent.
 */
class SearchConditions
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly string $where,
        public readonly array $parameters,
        public readonly ?string $relevanceExpression
    ) {
    }

    /**
     * False when the term produced nothing a fulltext index can match, so the
     * caller has to order by something other than relevance.
     */
    public function hasRelevance(): bool
    {
        return $this->relevanceExpression !== null;
    }
}
