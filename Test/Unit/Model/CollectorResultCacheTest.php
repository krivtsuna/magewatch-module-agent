<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use MageWatch\Agent\Model\CollectorResultCache;
use PHPUnit\Framework\TestCase;

class CollectorResultCacheTest extends TestCase
{
    public function test_zero_ttl_always_collects(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('load');
        $cache->expects($this->never())->method('save');

        $store = new CollectorResultCache($cache);
        $calls = 0;
        $result = $store->remember('cron', 0, function () use (&$calls): array {
            $calls++;

            return ['cron' => ['ok' => true]];
        });

        $this->assertSame(['cron' => ['ok' => true]], $result);
        $this->assertSame(1, $calls);
    }

    public function test_cache_hit_skips_producer(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('{"catalog_health":{"missing_price":0}}');
        $cache->expects($this->never())->method('save');

        $store = new CollectorResultCache($cache);
        $result = $store->remember('catalog_health', 3600, function (): array {
            $this->fail('producer should not run on a cache hit');
        });

        $this->assertSame(['catalog_health' => ['missing_price' => 0]], $result);
    }

    public function test_cache_miss_saves_and_force_refreshes(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->once())->method('save')->with(
            '{"composer":{"packages":[]}}',
            'magewatch_collector_composer',
            [CollectorResultCache::CACHE_TAG],
            3600
        );

        $store = new CollectorResultCache($cache);
        $result = $store->remember('composer', 3600, static fn (): array => ['composer' => ['packages' => []]]);

        $this->assertSame(['composer' => ['packages' => []]], $result);

        $forced = $this->createMock(CacheInterface::class);
        $forced->expects($this->never())->method('load');
        $forced->method('save');
        $forcedStore = new CollectorResultCache($forced);
        $calls = 0;
        $forcedStore->remember('composer', 3600, function () use (&$calls): array {
            $calls++;

            return ['composer' => ['packages' => []]];
        }, true);
        $this->assertSame(1, $calls);
    }
}
