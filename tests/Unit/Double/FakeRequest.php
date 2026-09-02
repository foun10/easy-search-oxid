<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

/**
 * The two request accessors the module uses, answering what the test set.
 *
 * Deliberately not a mock of OXID's Request: the point of these suites is that
 * they run with no OXID class loadable, and the production code only ever asks
 * this object two questions. Values are `mixed` because that is what arrives -
 * `?langId[]=1` hands a controller an array, which is exactly the case the
 * cast rules in RequestValues exist for.
 */
class FakeRequest
{
    /**
     * @param array<string, mixed> $escaped
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public array $escaped = [],
        public array $raw = []
    ) {
    }

    /** @var string[] Every parameter that was asked for, in order */
    public array $asked = [];

    public function getRequestEscapedParameter(string $name, $default = null): mixed
    {
        $this->asked[] = $name;

        return $this->escaped[$name] ?? $default;
    }

    public function getRequestParameter(string $name, $default = null): mixed
    {
        $this->asked[] = $name;

        return $this->raw[$name] ?? $default;
    }
}
