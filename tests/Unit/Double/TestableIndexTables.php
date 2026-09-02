<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Index\MySql\IndexTables;

/**
 * IndexTables with the four statements it would run recorded instead.
 *
 * The name building is the real one - `foun10easysearchindex_s2`, `_tmp`,
 * `_old` - because the writer's whole argument is about which of those names
 * it touches in which order, and a stubbed name would prove nothing about it.
 */
class TestableIndexTables extends IndexTables
{
    /** @var string[] Live tables that were created on demand */
    public array $created = [];

    /** @var array<int, array{table: string, fulltext: bool}> */
    public array $shadowsCreated = [];

    /** @var string[] In the order they were dropped */
    public array $dropped = [];

    /** @var string[] Tables the database is pretending to have */
    public array $existing = [];

    public int $forgets = 0;

    public function __construct()
    {
    }

    public function ensure(string $table, int $shopId, bool $withFulltext = true): string
    {
        $name = $this->name($table, $shopId);
        $this->created[] = $name;

        return $name;
    }

    public function createShadow(string $table, int $shopId, bool $withFulltext = true): string
    {
        $name = $this->shadow($this->name($table, $shopId));
        $this->shadowsCreated[] = ['table' => $name, 'fulltext' => $withFulltext];

        return $name;
    }

    public function exists(string $name): bool
    {
        return in_array($name, $this->existing, true);
    }

    public function drop(string $name): void
    {
        $this->dropped[] = $name;
    }

    public function forget(): void
    {
        $this->forgets++;
    }

    /**
     * @return string[]
     */
    public function shadowTableNames(): array
    {
        return array_column($this->shadowsCreated, 'table');
    }
}
