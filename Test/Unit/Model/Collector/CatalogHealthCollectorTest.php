<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\Collector\CatalogHealthCollector;
use PHPUnit\Framework\TestCase;

class CatalogHealthCollectorTest extends TestCase
{
    public function test_collector_code(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $clock = $this->createMock(Clock::class);
        $collector = new CatalogHealthCollector($resource, $clock, $this->missCache());

        $this->assertSame('catalog_health', $collector->getCode());
    }

    public function test_collect_returns_empty_shape_when_catalog_missing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $clock = $this->createMock(Clock::class);
        $collector = new CatalogHealthCollector($resource, $clock, $this->missCache());

        $this->assertSame([
            'catalog_health' => [
                'missing_price' => 0,
                'missing_price_skus' => [],
                'missing_image' => 0,
                'missing_image_skus' => [],
                'configurables_without_options' => 0,
                'configurables_without_options_skus' => [],
                'bestsellers_oos' => [],
            ],
        ], $collector->collect());
    }

    public function test_collect_aggregates_counts_and_sample_skus(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('select')->willReturn($select);
        // attribute ids for price/status/visibility/image/status/visibility/status + bestsellers stock query uses fetchCol
        $connection->method('fetchOne')->willReturn(10);
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(
            ['PRICE-1', 'PRICE-2'],
            ['IMG-1'],
            ['CFG-1', 'CFG-2', 'CFG-3'],
            ['TOP-1'],
        );
        $connection->method('fetchAll')->willReturn([
            ['sku' => 'TOP-1', 'qty_ordered' => 12],
        ]);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-28 12:00:00'));

        $collector = new CatalogHealthCollector($resource, $clock, $this->missCache());
        $result = $collector->collect();

        $this->assertSame(2, $result['catalog_health']['missing_price']);
        $this->assertSame(['PRICE-1', 'PRICE-2'], $result['catalog_health']['missing_price_skus']);
        $this->assertSame(1, $result['catalog_health']['missing_image']);
        $this->assertSame(['IMG-1'], $result['catalog_health']['missing_image_skus']);
        $this->assertSame(3, $result['catalog_health']['configurables_without_options']);
        $this->assertSame(['CFG-1', 'CFG-2', 'CFG-3'], $result['catalog_health']['configurables_without_options_skus']);
        $this->assertSame([
            ['sku' => 'TOP-1', 'qty_ordered' => 12.0],
        ], $result['catalog_health']['bestsellers_oos']);
    }

    public function test_bestsellers_use_cached_top_skus_and_still_check_stock(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(10);
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(
            [],
            [],
            [],
            ['CACHED-1'],
        );
        $connection->expects($this->never())->method('fetchAll');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('[{"sku":"CACHED-1","qty_ordered":4}]');

        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-28 12:00:00'));

        $result = (new CatalogHealthCollector($resource, $clock, $cache))->collect();

        $this->assertSame([
            ['sku' => 'CACHED-1', 'qty_ordered' => 4.0],
        ], $result['catalog_health']['bestsellers_oos']);
    }

    public function test_bestsellers_fall_back_to_order_items_when_aggregate_missing(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(
            static fn (string $table): bool => $table !== 'sales_bestsellers_aggregated_daily'
        );
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(10);
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(
            [],
            [],
            [],
            ['RAW-1'],
        );
        $connection->method('fetchAll')->willReturn([
            ['sku' => 'RAW-1', 'qty_ordered' => 3],
        ]);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-28 12:00:00'));

        $result = (new CatalogHealthCollector($resource, $clock, $this->missCache()))->collect();

        $this->assertSame([
            ['sku' => 'RAW-1', 'qty_ordered' => 3.0],
        ], $result['catalog_health']['bestsellers_oos']);
    }

    private function missCache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        return $cache;
    }
}
