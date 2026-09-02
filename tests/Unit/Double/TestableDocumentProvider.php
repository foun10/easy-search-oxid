<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Index\DocumentProvider;

/**
 * DocumentProvider with the catalogue supplied by the test.
 *
 * The provider's four database seams are answered from arrays here, chosen by
 * the view name the query reads from - which is why getViewName() returns a
 * name that carries the table, the shop and the language. That also lets the
 * tests assert on the SQL itself where the SQL is the point: keyset paging,
 * the WHERE that keeps variant parents out, and the attribute restriction.
 *
 * Article rows come from a queue, so a test can hand out several batches and
 * let provide() walk them the way a reindex does.
 */
class TestableDocumentProvider extends DocumentProvider
{
    /** @var array<int, array<int, array<string, mixed>>> One entry per batch, in order */
    public array $articleBatches = [];

    /** @var array<int, array<string, mixed>> */
    public array $attributeRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $categoryAssignmentRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $categoryRows = [];

    public int $articleCount = 0;

    /** @var string[] Every statement that went through the row seam */
    public array $queries = [];

    /** @var array<int, array<string, mixed>> The bound parameters of each of them, in the same order */
    public array $queryParameters = [];

    /** @var string[] */
    public array $countQueries = [];

    /** @var string[] */
    public array $viewNames = [];

    public int $categoryTreeReads = 0;

    public int $articleBatchReads = 0;

    /** Whether the installation is to answer as an Enterprise one, with oxfield2shop */
    public bool $hasShopPriceOverrides = false;

    public int $editionChecks = 0;

    /**
     * @return string[]
     */
    public function queriesAgainst(string $table): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => str_contains($sql, 'oxv_' . $table . '_')
        ));
    }

    /**
     * The bound parameters of the first statement against a table.
     *
     * @return array<string, mixed>
     */
    public function parametersFor(string $table): array
    {
        foreach ($this->queries as $index => $sql) {
            if (str_contains($sql, 'oxv_' . $table . '_')) {
                return $this->queryParameters[$index];
            }
        }

        return [];
    }

    protected function fetchRows(string $sql, array $parameters = []): array
    {
        $this->queries[] = $sql;
        $this->queryParameters[] = $parameters;

        if (str_contains($sql, 'information_schema.tables')) {
            $this->editionChecks++;

            return $this->hasShopPriceOverrides ? [['1' => 1]] : [];
        }

        if (str_contains($sql, 'oxv_oxobject2attribute_')) {
            return $this->attributeRows;
        }

        if (str_contains($sql, 'oxv_oxobject2category_')) {
            return $this->categoryAssignmentRows;
        }

        if (str_contains($sql, 'oxv_oxcategories_')) {
            $this->categoryTreeReads++;

            return $this->categoryRows;
        }

        $this->articleBatchReads++;

        return array_shift($this->articleBatches) ?? [];
    }

    protected function fetchCount(string $sql): int
    {
        $this->countQueries[] = $sql;

        return $this->articleCount;
    }

    protected function quote(string $value): string
    {
        return "'" . $value . "'";
    }

    protected function quoteList(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => $this->quote($value), $values));
    }

    protected function getViewName(string $table, int $langId, int $shopId): string
    {
        $view = 'oxv_' . $table . '_' . $shopId . '_' . $langId;
        $this->viewNames[] = $view;

        return $view;
    }

    /**
     * @param array<string, array{parentId: string, title: string}> $categories
     */
    public function buildCategoryPathPublic(string $categoryId, array $categories): string
    {
        return $this->buildCategoryPath($categoryId, $categories);
    }

    public function toDateOrNullPublic(?string $value): ?string
    {
        return $this->toDateOrNull($value);
    }

    public function buildDocumentIdPublic(string $articleId, int $shopId, int $langId): string
    {
        return $this->buildDocumentId($articleId, $shopId, $langId);
    }

    /**
     * @return string[]|null
     */
    public function getWantedAttributeIdsPublic(int $shopId): ?array
    {
        $this->setScope($shopId);

        return $this->getWantedAttributeIds();
    }
}
