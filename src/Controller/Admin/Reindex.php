<?php
declare(strict_types=1);

namespace foun10\EasySearch\Controller\Admin;

/**
 * The constants of the browser-driven rebuild.
 *
 * A class rather than constants on the ReindexPhases trait, for two reasons.
 * The plain one: **constants in traits are PHP 8.2**, and this module's floor
 * is 8.1 - a trait carrying them is a fatal parse error there, not a graceful
 * degradation, so the module would not load at all on the PHP version its own
 * composer.json allows.
 *
 * The better one: they were declared twice, on the trait and again on
 * AttributeController, which is a drift waiting to happen. Both screens that
 * can start a rebuild, and the JavaScript that drives them, agree on these
 * names; one place to read them is worth more than the convenience of `self::`.
 */
final class Reindex
{
    /**
     * The phase names, which are the contract with the browser: the script
     * sends one back on every tick and switches on what it gets.
     */
    public const PHASE_CLEAR = 'clear';
    public const PHASE_INDEX = 'index';
    public const PHASE_CATEGORY = 'category';
    public const PHASE_DICTIONARY = 'dictionary';

    /**
     * Documents per tick when the browser has not measured anything yet.
     *
     * The browser adjusts it from there: it times each tick and asks for the
     * size that would land near its target, so the same screen walks a slow
     * local container in small steps and a fast server in large ones. A fixed
     * number is wrong on one of the two by definition - the per-tick cost is
     * dominated by booting the shop, and how much catalogue fits beside that
     * boot is a property of the machine, not of the code.
     */
    public const BATCH_SIZE = 200;

    /**
     * Bounds for what the browser may ask for.
     *
     * Measured on the local container: building a batch costs about a second
     * almost regardless of its size - 782 ms for 500 documents, 1,225 ms for
     * 2,500 - because the cost per tick is the queries, not the documents. So
     * a larger batch is nearly free per document (1.56 ms at 500, 0.49 ms at
     * 2,500) and the ceiling is not about speed.
     *
     * It is about the write: a tick's documents go to the database as one
     * INSERT, and a document's search text averages about a kilobyte. Two
     * thousand of them make a statement of a few megabytes, which stays far
     * inside a conservative max_allowed_packet. Memory is not the limit -
     * 2,500 documents measured at 10 MB against a 2 GB limit.
     */
    public const BATCH_MIN = 50;
    public const BATCH_MAX = 2000;

    /**
     * Rows removed per clear tick.
     *
     * Clearing a scope in one statement measured 21 seconds on a large
     * catalogue - past what a web request may take, and the very thing the
     * batching exists to avoid.
     */
    public const CLEAR_BATCH_SIZE = 5000;
}
