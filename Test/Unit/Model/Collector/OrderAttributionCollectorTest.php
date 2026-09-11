<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\Collector\OrderAttributionCollector;
use MageWatch\Agent\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderAttributionCollectorTest extends TestCase
{
    private AdapterInterface&MockObject $connection;

    private Config&MockObject $config;

    private OrderAttributionCollector $collector;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getAttributionWindowDays')->willReturn(30);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-09-10T10:05:00+00:00'));

        $this->collector = new OrderAttributionCollector($resource, $clock, $this->config);
    }

    public function test_get_code(): void
    {
        $this->assertSame('order_attribution', $this->collector->getCode());
    }

    public function test_missing_table_returns_empty_shape(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);

        $this->assertSame([
            'order_attribution' => [
                'window_days' => 7,
                'attribution_window_days' => 30,
                'coverage' => [
                    'orders' => 0,
                    'attributed' => 0,
                ],
                'last_touch' => [],
                'first_touch' => [],
            ],
        ], $this->collector->collect());
    }

    public function test_groups_and_caps_daily_rows(): void
    {
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchRow')->willReturn(['orders' => '12', 'attributed' => '9']);

        $last = [
            ['day' => '2026-09-10', 'source' => 'google', 'medium' => 'cpc', 'campaign' => 'brand', 'cnt' => '5', 'revenue' => '500.1'],
            ['day' => '2026-09-09', 'source' => 'newsletter', 'medium' => 'email', 'campaign' => 'spring', 'cnt' => '2', 'revenue' => '80'],
        ];
        for ($i = 0; $i < 51; $i++) {
            $last[] = [
                'day' => '2026-09-08',
                'source' => 'src'.$i,
                'medium' => 'cpc',
                'campaign' => 'c'.$i,
                'cnt' => (string) (51 - $i),
                'revenue' => (string) (51 - $i),
            ];
        }

        $this->connection->method('fetchAll')->willReturnOnConsecutiveCalls($last, [
            ['day' => '2026-09-10', 'source' => 'google', 'medium' => 'organic', 'campaign' => '(not set)', 'cnt' => '3', 'revenue' => '90'],
        ]);

        $result = $this->collector->collect();
        $payload = $result['order_attribution'];

        $this->assertSame(['orders' => 12, 'attributed' => 9], $payload['coverage']);
        $this->assertSame('google', $payload['last_touch'][0]['source']);
        $this->assertSame(5, $payload['last_touch'][0]['count']);
        $this->assertSame(500.1, $payload['last_touch'][0]['revenue']);

        $day8 = array_values(array_filter(
            $payload['last_touch'],
            static fn (array $row): bool => $row['day'] === '2026-09-08'
        ));
        $this->assertCount(51, $day8);
        $other = end($day8);
        $this->assertSame('(other)', $other['source']);
        $this->assertSame(1, $other['count']);
        $this->assertSame(1.0, $other['revenue']);

        $this->assertSame('google', $payload['first_touch'][0]['source']);
        $this->assertSame('organic', $payload['first_touch'][0]['medium']);
    }
}
