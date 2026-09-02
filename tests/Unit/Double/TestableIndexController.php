<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Controller\Admin\IndexController;
use RuntimeException;

/**
 * IndexController with the database, the shop and the date formatter supplied
 * by the test.
 *
 * The screen is a status table over three tables of the index, so what it has
 * to get right is which scope it reads and what it does when a table is not
 * there yet - which is the normal state of a shop that has never been indexed,
 * and the reason the query seam sits below the catch rather than above it.
 *
 * Answers are keyed by table name because that is what distinguishes the
 * statements, which also lets a test assert on the SQL where the SQL is the
 * point.
 */
class TestableIndexController extends IndexController
{
    public int $currentShopId = 1;

    /** @var array<string, int> COUNT(*) per table name */
    public array $counts = [];

    /** @var array<string, string> MAX(OXTIMESTAMP) per table name */
    public array $timestamps = [];

    /** @var string[] Tables that are not there yet; asking one throws */
    public array $missingTables = [];

    /** @var string[] Every statement that went through the seam, in order */
    public array $queries = [];

    /** @var array<int, array<string, mixed>> The bound parameters of each, in the same order */
    public array $queryParameters = [];

    /** @var string[] Values handed to the date formatter */
    public array $formatted = [];

    /** @var array<string, object> Container entries, keyed by service id */
    public array $services = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(array $parameters = [])
    {
        $this->request = new FakeRequest($parameters);
    }

    public FakeRequest $request;

    protected function getRequest()
    {
        return $this->request;
    }

    protected function getCurrentShopId(): int
    {
        return $this->currentShopId;
    }

    /**
     * A recognisable rendering rather than a real one: what the screen has to
     * get right is which value it formats and when it does not bother.
     */
    protected function formatDate(string $value): string
    {
        $this->formatted[] = $value;

        return 'formatted:' . $value;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<int, array<string, mixed>>
     */
    protected function query(string $sql, array $parameters = []): array
    {
        $this->queries[] = $sql;
        $this->queryParameters[] = $parameters;

        $table = $this->tableOf($sql);

        if (in_array($table, $this->missingTables, true)) {
            throw new RuntimeException("Table '" . $table . "' doesn't exist");
        }

        if (str_contains($sql, 'COUNT(*)')) {
            return [['VALUE' => $this->counts[$table] ?? 0]];
        }

        return [['VALUE' => $this->timestamps[$table] ?? '']];
    }

    protected function getService(string $id): object
    {
        return $this->services[$id]
            ?? throw new RuntimeException('no service registered for ' . $id);
    }

    /**
     * @return string[]
     */
    public function queriesAgainst(string $table): array
    {
        return array_values(array_filter(
            $this->queries,
            fn (string $sql): bool => $this->tableOf($sql) === $table
        ));
    }

    protected function tableOf(string $sql): string
    {
        return preg_match('/FROM\s+(\S+)/', $sql, $matches) === 1 ? $matches[1] : '';
    }
}
