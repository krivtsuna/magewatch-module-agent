<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use MageWatch\Agent\Model\Collector\SystemCollector;
use Magento\Framework\App\Cache\StateInterface as CacheStateInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SystemCollectorTest extends TestCase
{
    private Filesystem&MockObject $filesystem;
    private ProductMetadataInterface&MockObject $productMetadata;
    private State&MockObject $appState;
    private MaintenanceMode&MockObject $maintenanceMode;
    private TypeListInterface&MockObject $cacheTypeList;
    private CacheStateInterface&MockObject $cacheState;
    private StoreManagerInterface&MockObject $storeManager;
    private ReadInterface&MockObject $rootDirectory;
    private SystemCollector $collector;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->productMetadata = $this->createMock(ProductMetadataInterface::class);
        $this->appState = $this->createMock(State::class);
        $this->maintenanceMode = $this->createMock(MaintenanceMode::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->cacheState = $this->createMock(CacheStateInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->rootDirectory = $this->createMock(ReadInterface::class);

        $this->filesystem->method('getDirectoryRead')
            ->with(DirectoryList::ROOT)
            ->willReturn($this->rootDirectory);
        $this->rootDirectory->method('getAbsolutePath')->willReturn(sys_get_temp_dir());

        $this->productMetadata->method('getVersion')->willReturn('2.4.7');
        $this->productMetadata->method('getEdition')->willReturn('Community');
        $this->appState->method('getMode')->willReturn('production');
        $this->maintenanceMode->method('isOn')->willReturn(false);
        $this->cacheTypeList->method('getTypes')->willReturn(['config' => new \stdClass(), 'layout' => new \stdClass()]);
        $this->cacheState->method('isEnabled')->willReturnMap([
            ['config', true],
            ['layout', false],
        ]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->willReturn('https://shop.example/');
        $this->storeManager->method('getStores')->willReturn([$store]);

        $this->collector = new SystemCollector(
            $this->filesystem,
            $this->productMetadata,
            $this->appState,
            $this->maintenanceMode,
            $this->cacheTypeList,
            $this->cacheState,
            $this->storeManager
        );
    }

    public function testGetCode(): void
    {
        $this->assertSame('system', $this->collector->getCode());
    }

    public function testCollectReturnsMagentoMetadataAndDisabledCaches(): void
    {
        $result = $this->collector->collect();

        $this->assertSame('2.4.7', $result['magento']['version']);
        $this->assertSame('Community', $result['magento']['edition']);
        $this->assertSame('production', $result['magento']['mode']);
        $this->assertFalse($result['magento']['maintenance']);
        $this->assertSame(['https://shop.example/'], $result['magento']['store_base_urls']);
        $this->assertSame(['layout'], $result['system']['disabled_caches']);
        $this->assertArrayHasKey('disk_free_bytes', $result['system']);
        $this->assertArrayHasKey('disk_free_percent', $result['system']);
    }
}
