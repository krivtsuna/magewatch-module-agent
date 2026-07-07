<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\Collector\CronCollector;
use MageWatch\Agent\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CronCollectorTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private Config&MockObject $config;
    private Clock&MockObject $clock;
    private CronCollector $collector;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->select = $this->createMock(Select::class);
        $this->config = $this->createMock(Config::class);
        $this->clock = $this->createMock(Clock::class);

        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->select->method('group')->willReturnSelf();

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
        $this->connection->method('select')->willReturn($this->select);

        $this->clock->method('now')->willReturn(
            new \DateTimeImmutable('2026-07-03T10:05:00+00:00')
        );
        $this->config->method('getStuckCronThresholdMinutes')->willReturn(30);

        $this->collector = new CronCollector($this->resourceConnection, $this->config, $this->clock);
    }

    public function testGetCode(): void
    {
        $this->assertSame('cron', $this->collector->getCode());
    }

    public function testCollectAssemblesCronSection(): void
    {
        $this->connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                ['job_code' => 'indexer_reindex_all_invalid', 'executed_at' => '2026-07-03 09:10:00'],
            ],
            [
                ['job_code' => 'sales_grid_order_async_insert', 'cnt' => '3'],
            ],
            [
                ['job_code' => 'newsletter_send_all', 'cnt' => '1'],
            ]
        );

        $this->connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            '48211',
            '2026-07-03 10:04:00'
        );

        $result = $this->collector->collect();

        $this->assertSame([
            'cron' => [
                'stuck' => [
                    ['job_code' => 'indexer_reindex_all_invalid', 'executed_at' => '2026-07-03T09:10:00+00:00'],
                ],
                'missed_last_hour' => [
                    ['job_code' => 'sales_grid_order_async_insert', 'count' => 3],
                ],
                'errors_last_hour' => [
                    ['job_code' => 'newsletter_send_all', 'count' => 1],
                ],
                'schedule_rows' => 48211,
                'last_success_at' => '2026-07-03T10:04:00+00:00',
            ],
        ], $result);
    }

    public function testCollectHandlesNoSuccessfulRuns(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('fetchOne')->willReturnOnConsecutiveCalls('0', false);

        $result = $this->collector->collect();

        $this->assertNull($result['cron']['last_success_at']);
        $this->assertSame(0, $result['cron']['schedule_rows']);
    }
}
