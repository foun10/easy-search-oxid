<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Core\RequestQueryFactory;

/**
 * RequestQueryFactory with the request and the shop supplied by the test.
 *
 * The class is one long argument about untrusted URL parameters - which of
 * them count as IDs, where the filter caps bite, what a sort fragment maps to.
 * None of that needs a shop; it only needs the five values the shop would have
 * handed over. Escaped and raw parameters are kept in separate arrays because
 * the production class reads the search term through the raw one on purpose.
 */
class TestableRequestQueryFactory extends RequestQueryFactory
{
    /** @var array<string, mixed> */
    public array $escaped = [];

    /** @var array<string, mixed> */
    public array $raw = [];

    public int $shopId = 1;

    public int $languageId = 0;

    public int $configuredPageSize = 24;

    /** @var string[] */
    public array $escapedReads = [];

    /** @var string[] */
    public array $rawReads = [];

    protected function getEscapedParameter(string $parameter): mixed
    {
        $this->escapedReads[] = $parameter;

        return $this->escaped[$parameter] ?? null;
    }

    protected function getRawParameter(string $parameter): mixed
    {
        $this->rawReads[] = $parameter;

        return $this->raw[$parameter] ?? null;
    }

    protected function getShopId(): int
    {
        return $this->shopId;
    }

    protected function getLanguageId(): int
    {
        return $this->languageId;
    }

    protected function getConfiguredPageSize(): int
    {
        return $this->configuredPageSize;
    }

    /**
     * Exposed so the ID rule can be checked directly instead of through the
     * three parameters that happen to use it.
     */
    public function isIdPublic(string $value): bool
    {
        return $this->isId($value);
    }

    public function getStringOrNullPublic(string $parameter): ?string
    {
        return $this->getStringOrNull($parameter);
    }

    public function getFloatOrNullPublic(string $parameter): ?float
    {
        return $this->getFloatOrNull($parameter);
    }
}
