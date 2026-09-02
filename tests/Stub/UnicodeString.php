<?php

declare(strict_types=1);

namespace Symfony\Component\String;

/**
 * Minimal stand-in for symfony/string's UnicodeString.
 *
 * ModuleSettingServiceInterface::getString() returns one of these, and the
 * module only ever casts it to a string. symfony/string is a dependency of the
 * shop rather than of this module, so the unit suite - which runs without the
 * shop - supplies just enough of it to satisfy the type.
 */
class UnicodeString
{
    public function __construct(private string $value = '')
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
