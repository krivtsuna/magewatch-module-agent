<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use MageWatch\Agent\Model\Collector\StorefrontProbeCollector;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StorefrontProbeCollectorTest extends TestCase
{
    public function testCollectReturnsEmptyProbeWhenStoreUrlMissing(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->willReturn('');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $collector = new StorefrontProbeCollector($storeManager);
        $result = $collector->collect();

        $this->assertArrayHasKey('storefront_probe', $result);
        $this->assertSame('none', $result['storefront_probe']['probe_method']);
        $this->assertFalse($result['storefront_probe']['homepage_ok']);
        $this->assertFalse($result['storefront_probe']['checkout_ok']);
    }

    public function testCollectorCode(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $collector = new StorefrontProbeCollector($storeManager);

        $this->assertSame('storefront_probe', $collector->getCode());
    }
}
