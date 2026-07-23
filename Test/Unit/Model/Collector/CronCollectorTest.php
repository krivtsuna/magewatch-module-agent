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
        $this->select->method('order')->willReturnSelf();
        $this->select->method('limit')->willReturnSelf();
        $this->select->method('distinct')->willReturnSelf();

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('fetchCol')->willReturn([]);

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

    public function testCollectAssemblesCronSectionWithGroupedErrors(): void
    {
        $this->connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            // stuck
            [
                ['job_code' => 'indexer_reindex_all_invalid', 'executed_at' => '2026-07-03 09:10:00'],
            ],
            // missed
            [
                ['job_code' => 'sales_grid_order_async_insert', 'cnt' => '3'],
            ],
            // errors (job_code + messages)
            [
                [
                    'job_code' => 'newsletter_send_all',
                    'messages' => "Unable to send  \nmail",
                    'cnt' => '2',
                ],
                [
                    'job_code' => 'newsletter_send_all',
                    'messages' => 'SMTP timeout',
                    'cnt' => '1',
                ],
            ],
            // last success per group (ignored empty)
            [],
            [],
            [],
        );

        $this->connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            '48211',
            '2026-07-03 10:04:00'
        );

        $result = $this->collector->collect();

        $this->assertSame(
            ['job_code' => 'indexer_reindex_all_invalid', 'executed_at' => '2026-07-03T09:10:00+00:00'],
            $result['cron']['stuck'][0]
        );
        $this->assertSame(
            [['job_code' => 'sales_grid_order_async_insert', 'count' => 3]],
            $result['cron']['missed_last_hour']
        );
        $this->assertSame(
            [
                ['job_code' => 'newsletter_send_all', 'count' => 2, 'message' => 'Unable to send mail'],
                ['job_code' => 'newsletter_send_all', 'count' => 1, 'message' => 'SMTP timeout'],
            ],
            $result['cron']['errors_last_hour']
        );
        $this->assertSame(48211, $result['cron']['schedule_rows']);
        $this->assertSame('2026-07-03T10:04:00+00:00', $result['cron']['last_success_at']);
        $this->assertArrayHasKey('groups', $result['cron']);
    }

    public function testCollectHandlesNoSuccessfulRuns(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('fetchOne')->willReturnOnConsecutiveCalls('0', false);

        $result = $this->collector->collect();

        $this->assertNull($result['cron']['last_success_at']);
        $this->assertSame(0, $result['cron']['schedule_rows']);
        $this->assertSame([], $result['cron']['missed_last_hour']);
        $this->assertSame([], $result['cron']['errors_last_hour']);
    }
}
