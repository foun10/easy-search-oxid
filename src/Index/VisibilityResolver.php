<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index;

use OxidEsales\Eshop\Core\Registry;

/**
 * Decides whether an indexed article may appear in a result list.
 *
 * One place, evaluated once at index time, written into FOUN10VISIBLE. The
 * engine then filters a single boolean instead of reassembling the rule out of
 * raw columns on every query.
 *
 * Three reasons it is shaped this way:
 *
 *  - the rule is meant to grow. Whatever gets added later - articles without a
 *    picture, without a price, restricted to a customer group - lands here and
 *    no query has to learn about it;
 *  - a boolean is something every backend can filter. Re-expressing
 *    "oxstockflag != 2 OR oxstock > 0" in Meilisearch's filter dialect is the
 *    kind of thing that does not survive an engine swap;
 *  - it costs nothing in freshness. FOUN10STOCK is already a snapshot taken at
 *    index time, so a decision derived from it goes stale at exactly the same
 *    rate the raw number does.
 *
 * The price is that runtime configuration is baked in: flipping blUseStock
 * changes what should be visible, and the index only learns about it on the
 * next rebuild. That is the right trade for a switch nobody touches twice, but
 * it is a trade - a query time rule would have picked it up immediately.
 *
 * Whoever adds incremental updates later: a stock change has to rewrite
 * FOUN10VISIBLE, not just FOUN10STOCK, or a restocked article stays hidden.
 */
class VisibilityResolver
{
    /**
     * OXID's "hide this article when it is out of stock" flag.
     *
     * The other flags (1, 3, 4) all keep the article listed at zero stock -
     * they differ in whether it can still be ordered, which is a question for
     * the detail page and not for whether it appears in a list.
     */
    protected const STOCKFLAG_HIDE_WHEN_EMPTY = 2;

    protected ?bool $stockEnabled = null;

    /**
     * @param array<string, mixed> $row Raw article row as the provider read it
     */
    public function isVisible(array $row): bool
    {
        if ((int) ($row['OXACTIVE'] ?? 0) !== 1) {
            return false;
        }

        return $this->passesStockCheck($row);
    }

    /**
     * Mirrors Article::getStockCheckQuery(): an article disappears from a list
     * only when its own flag says so AND it is actually empty.
     *
     * Deliberately not "stock > 0". That was the rule before this class existed
     * and it hid every out of stock article regardless of its flag, which is
     * stricter than the shop itself is - a line kept browsable while it is
     * being resupplied would have vanished from search only.
     *
     * oxvarstock is not consulted: it carries the summed stock of a parent's
     * variants, and parents are never indexed - only variants and standalone
     * articles are, each with its own oxstock.
     *
     * @param array<string, mixed> $row
     */
    protected function passesStockCheck(array $row): bool
    {
        if (!$this->isStockEnabled()) {
            return true;
        }

        if ((int) ($row['OXSTOCKFLAG'] ?? 1) !== self::STOCKFLAG_HIDE_WHEN_EMPTY) {
            return true;
        }

        return (float) ($row['OXSTOCK'] ?? 0) > 0;
    }

    /**
     * Read once per run: it is a shop setting, and the resolver is asked about
     * every article in the catalogue.
     */
    protected function isStockEnabled(): bool
    {
        if ($this->stockEnabled === null) {
            $this->stockEnabled = (bool) Registry::getConfig()->getConfigParam('blUseStock');
        }

        return $this->stockEnabled;
    }
}
