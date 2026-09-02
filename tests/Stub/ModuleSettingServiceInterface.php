<?php

declare(strict_types=1);

namespace OxidEsales\EshopCommunity\Internal\Framework\Module\Facade;

use Symfony\Component\String\UnicodeString;

/**
 * Stand-in for the shop's module setting service.
 *
 * ModuleSettings type-hints it in its constructor, so a unit test cannot build
 * one without the interface existing. Declaring it here keeps the settings
 * caching - which is ordinary logic - testable without the shop, in the same
 * way tests/Stub carries the other framework types the module names.
 *
 * The signatures mirror the real interface; only the methods the module
 * actually calls need to be right, but the rest are cheap to carry and stop a
 * mock from looking different to the real thing.
 */
interface ModuleSettingServiceInterface
{
    public function getInteger(string $name, string $moduleId): int;

    public function getFloat(string $name, string $moduleId): float;

    public function getString(string $name, string $moduleId): UnicodeString;

    public function getBoolean(string $name, string $moduleId): bool;

    public function getCollection(string $name, string $moduleId): array;

    public function saveInteger(string $name, int $value, string $moduleId): void;

    public function saveFloat(string $name, float $value, string $moduleId): void;

    public function saveString(string $name, string $value, string $moduleId): void;

    public function saveBoolean(string $name, bool $value, string $moduleId): void;

    public function saveCollection(string $name, array $value, string $moduleId): void;
}
