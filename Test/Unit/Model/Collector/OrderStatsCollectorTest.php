<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\Collector\OrderStatsCollector;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderStatsCollectorTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private Clock&MockObject $clock;
    private OrderStatsCollector $collector;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->select = $this->createMock(Select::class);
        $this->clock = $this->createMock(Clock::class);

        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->select->method('group')->willReturnSelf();

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
        $this->connection->method('select')->willReturn($this->select);
        $this->clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-03T10:05:00+00:00'));

        $this->collector = new OrderStatsCollector($this->resourceConnection, $this->clock);
    }

    public function testGetCode(): void
    {
        $this->assertSame('order_stats', $this->collector->getCode());
    }

    public function testCollectReturnsHourlyBucketsAndPendingPaymentStuck(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['hour_bucket' => '2026-07-03 09:00:00', 'cnt' => '4', 'revenue' => '312.50'],
        ]);
        $this->connection->method('fetchOne')->willReturn('2');

        $result = $this->collector->collect();

        $this->assertCount(168, $result['orders_hourly']);

        $bucket09 = null;
        foreach ($result['orders_hourly'] as $bucket) {
            if ($bucket['hour'] === '2026-07-03T09:00:00+00:00') {
                $bucket09 = $bucket;
                break;
            }
        }

        $this->assertNotNull($bucket09);
        $this->assertSame(4, $bucket09['count']);
        $this->assertSame(312.5, $bucket09['revenue']);
        $this->assertSame(['pending_payment_stuck' => 2], $result['orders']);
    }
}
