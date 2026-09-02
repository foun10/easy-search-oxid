<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Core;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Tests\Unit\Double\TestableModuleSettings;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\String\UnicodeString;

/**
 * Typed access to the module settings, with two behaviours worth proving.
 *
 * The memoisation is not premature optimisation: OXID resolves a module setting
 * through the file-based project configuration, and one read was measured at
 * 15 ms. The facet sidebar asks for the value limit once per facet, so eight
 * facets spent 120 ms answering the same question - on a page where the search
 * itself takes single-digit milliseconds.
 *
 * And it is keyed by shop rather than by name alone, because a console command
 * walks every subshop in one process. A value cached across that switch would
 * be the wrong shop's.
 */
class ModuleSettingsTest extends TestCase
{
    private function service(): ModuleSettingServiceInterface
    {
        return $this->createMock(ModuleSettingServiceInterface::class);
    }

    public function testAStringSettingIsReadThroughTheService(): void
    {
        $service = $this->service();
        $service->method('getString')->willReturn(new UnicodeString('meilisearch'));

        $settings = new TestableModuleSettings($service);

        $this->assertSame('meilisearch', $settings->getEngine());
    }

    public function testTypedGettersReturnTheirOwnTypes(): void
    {
        $service = $this->service();
        $service->method('getInteger')->willReturn(30);
        $service->method('getBoolean')->willReturn(true);

        $settings = new TestableModuleSettings($service);

        $this->assertSame(30, $settings->getFacetValueLimit());
        $this->assertTrue($settings->isCorrectionEnabled());
    }

    /**
     * The point of the cache: the sidebar asks for the same value once per
     * facet, and each miss costs a project-configuration read.
     */
    public function testTheSameSettingIsReadOnlyOncePerShop(): void
    {
        $service = $this->service();
        $service->expects($this->once())->method('getInteger')->willReturn(30);

        $settings = new TestableModuleSettings($service);

        $settings->getFacetValueLimit();
        $settings->getFacetValueLimit();
        $settings->getFacetValueLimit();
    }

    /**
     * Different settings are cached independently - one read must not satisfy
     * another key.
     */
    public function testDifferentSettingsAreCachedSeparately(): void
    {
        $service = $this->service();
        $service->expects($this->exactly(2))->method('getInteger')->willReturnOnConsecutiveCalls(30, 6);

        $settings = new TestableModuleSettings($service);

        $this->assertSame(30, $settings->getFacetValueLimit());
        $this->assertSame(6, $settings->getSuggestTermLimit());
        $this->assertSame(30, $settings->getFacetValueLimit(), 'served from the cache');
    }

    /**
     * The case the shop key exists for: a console command walks every subshop
     * in one process, and each has its own configuration.
     */
    public function testASettingIsReReadWhenTheShopChanges(): void
    {
        $service = $this->service();
        $service->expects($this->exactly(2))->method('getInteger')->willReturnOnConsecutiveCalls(30, 12);

        $settings = new TestableModuleSettings($service);

        $this->assertSame(30, $settings->getFacetValueLimit());

        $settings->shopId = 2;

        $this->assertSame(12, $settings->getFacetValueLimit(), 'shop 2 has its own value');
    }

    public function testAShopReturnedToIsStillCached(): void
    {
        $service = $this->service();
        $service->expects($this->exactly(2))->method('getInteger')->willReturnOnConsecutiveCalls(30, 12);

        $settings = new TestableModuleSettings($service);

        $settings->getFacetValueLimit();
        $settings->shopId = 2;
        $settings->getFacetValueLimit();
        $settings->shopId = 1;

        $this->assertSame(30, $settings->getFacetValueLimit(), 'shop 1 was never evicted');
    }

    /**
     * Settings only reach the shop through oe:module:install and
     * oe:module:deploy-configurations. Between a deployment that adds one and
     * the command that pushes it, a read throws - and for the connector
     * settings that must not take the shop down.
     */
    public function testAConnectorSettingThatDoesNotExistYetReadsAsEmpty(): void
    {
        $service = $this->service();
        $service->method('getString')->willThrowException(new RuntimeException('setting not found'));

        $settings = new TestableModuleSettings($service);

        $this->assertSame('', $settings->getMeiliHost());
        $this->assertSame('', $settings->getMeiliApiKey());
        $this->assertSame('', $settings->getMeiliIndexPrefix());
    }

    /**
     * A host pasted with a trailing space would otherwise be used verbatim and
     * fail to connect for a reason nobody can see.
     */
    public function testConnectorSettingsAreTrimmed(): void
    {
        $service = $this->service();
        $service->method('getString')->willReturn(new UnicodeString('  http://meili:7700  '));

        $settings = new TestableModuleSettings($service);

        $this->assertSame('http://meili:7700', $settings->getMeiliHost());
    }

    /**
     * A failing read is cached like any other, so a missing setting does not
     * throw once per facet on the same page.
     */
    public function testAFailedReadIsNotRetriedOnEveryCall(): void
    {
        $service = $this->service();
        $service->expects($this->once())->method('getString')
            ->willThrowException(new RuntimeException('setting not found'));

        $settings = new TestableModuleSettings($service);

        $settings->getMeiliHost();
        $settings->getMeiliHost();
    }

    public function testTheModuleIdAndEngineNamesAreStable(): void
    {
        $this->assertSame('foun10EasySearch', ModuleSettings::MODULE_ID);
        $this->assertSame('mysql', ModuleSettings::ENGINE_MYSQL);
        $this->assertSame('meilisearch', ModuleSettings::ENGINE_MEILISEARCH);
        $this->assertSame('null', ModuleSettings::ENGINE_NULL);
    }
}
