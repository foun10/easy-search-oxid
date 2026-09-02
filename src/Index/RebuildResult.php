<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index;

/**
 * Outcome of one derived-table rebuild - category assignments or facet
 * attributes.
 *
 * A rebuild that publishes nothing and a rebuild that refused to publish look
 * identical if all you return is a row count, and they mean opposite things:
 * the first says the catalogue is empty, the second says the source looked
 * wrong and the old assignments were kept. The caller has to be able to tell
 * them apart, because one of them is worth shouting about.
 */
class RebuildResult
{
    protected function __construct(
        protected readonly string $subject,
        protected readonly bool $published,
        protected readonly int $written,
        protected readonly int $previous,
        protected readonly int $available
    ) {
    }

    public static function published(string $subject, int $written, int $previous): self
    {
        return new self($subject, true, $written, $previous, $written);
    }

    /**
     * Refused: the source held far less than what is already published, which
     * is what a rebuild started in the middle of an ERP import looks like.
     */
    public static function skipped(string $subject, int $available, int $previous): self
    {
        return new self($subject, false, 0, $previous, $available);
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function getWritten(): int
    {
        return $this->written;
    }

    /**
     * Rows that were live before this run.
     */
    public function getPrevious(): int
    {
        return $this->previous;
    }

    /**
     * Rows the source could have produced.
     */
    public function getAvailable(): int
    {
        return $this->available;
    }

    /**
     * One line for a console or a log, saying what happened and why.
     */
    public function describe(): string
    {
        if ($this->published) {
            return sprintf('%d %s published (was %d)', $this->written, $this->subject, $this->previous);
        }

        return sprintf(
            'refused to publish: source holds %d %s against %d live ones - '
            . 'looks like an import is running, kept the existing index',
            $this->available,
            $this->subject,
            $this->previous
        );
    }
}
