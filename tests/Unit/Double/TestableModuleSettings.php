<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Core\ModuleSettings;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;

/**
 * ModuleSettings with the active shop under the test's control.
 *
 * Only the shop id is replaced, deliberately: the memoisation is keyed by it,
 * so overriding remember() itself would replace the very logic worth proving.
 * The setting service is an interface and arrives through the constructor, so
 * it needs no help.
 */
class TestableModuleSettings extends ModuleSettings
{
    public function __construct(
        ModuleSettingServiceInterface $moduleSettingService,
        public int $shopId = 1
    ) {
        parent::__construct($moduleSettingService);
    }

    protected function getShopId(): int
    {
        return $this->shopId;
    }
}
