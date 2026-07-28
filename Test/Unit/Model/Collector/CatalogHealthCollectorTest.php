<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

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
        $collector = new CatalogHealthCollector($resource, $clock);

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
        $collector = new CatalogHealthCollector($resource, $clock);

        $this->assertSame([
            'catalog_health' => [
                'missing_price' => 0,
                'missing_image' => 0,
                'configurables_without_options' => 0,
                'bestsellers_oos' => [],
            ],
        ], $collector->collect());
    }

    public function test_collect_aggregates_counts(): void
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
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            75, // price attribute id
            8,  // missing price
            3,  // missing image
            2,  // configurables without options
        );
        $connection->method('fetchAll')->willReturn([
            ['sku' => 'TOP-1', 'qty_ordered' => 12],
        ]);
        $connection->method('fetchCol')->willReturn(['TOP-1']);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-28 12:00:00'));

        $collector = new CatalogHealthCollector($resource, $clock);
        $result = $collector->collect();

        $this->assertSame(8, $result['catalog_health']['missing_price']);
        $this->assertSame(3, $result['catalog_health']['missing_image']);
        $this->assertSame(2, $result['catalog_health']['configurables_without_options']);
        $this->assertSame([
            ['sku' => 'TOP-1', 'qty_ordered' => 12.0],
        ], $result['catalog_health']['bestsellers_oos']);
    }
}
