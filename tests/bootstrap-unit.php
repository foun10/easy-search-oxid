<?php

declare(strict_types=1);

/**
 * Bootstrap for the unit suite.
 *
 * The module's own autoloader, plus stand-ins for the handful of framework
 * types the production code names in a signature. Everything reachable from
 * tests/Unit is otherwise free of OXID and of the database, which is what keeps
 * the suite fast enough to run on every keystroke.
 *
 * Each stub is a fallback, not an override: the guards autoload, so wherever
 * the real class or interface can be resolved it wins and the stub is never
 * loaded. That direction matters. symfony/string arrives here as a transitive
 * dependency of symfony/console, which the command tests need - with a
 * non-autoloading guard the stub would be defined first, the real class would
 * never load, and the suite would stay green while testing the stand-in.
 */
require __DIR__ . '/../vendor/autoload.php';

if (!class_exists(\Symfony\Component\String\UnicodeString::class)) {
    require __DIR__ . '/Stub/UnicodeString.php';
}

if (!interface_exists(
    \OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface::class
)) {
    require __DIR__ . '/Stub/ModuleSettingServiceInterface.php';
}

if (!class_exists(\OxidEsales\Eshop\Application\Controller\Admin\AdminController::class)) {
    require __DIR__ . '/Stub/AdminController.php';
}

if (!class_exists(\OxidEsales\Eshop\Application\Controller\FrontendController::class)) {
    require __DIR__ . '/Stub/FrontendController.php';
}
