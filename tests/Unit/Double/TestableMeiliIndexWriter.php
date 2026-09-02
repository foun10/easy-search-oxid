<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Index\Meili\MeiliIndexWriter;

/**
 * MeiliIndexWriter with its two shop touch points supplied by the test.
 *
 * Everything else already goes through the injected MeiliClient, so this is
 * the smallest double in the suite: one query and one view name.
 */
class TestableMeiliIndexWriter extends MeiliIndexWriter
{
    /** @var array<int, array<string, mixed>> The shop's category assignments */
    public array $assignmentRows = [];

    /** @var string[] */
    public array $queries = [];

    protected function fetchRows(string $sql): array
    {
        $this->queries[] = $sql;

        return $this->assignmentRows;
    }

    protected function getViewName(string $table, int $langId, int $shopId): string
    {
        return 'oxv_' . $table . '_' . $shopId . '_' . $langId;
    }
}
