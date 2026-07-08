<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\CollectorPool;
use MageWatch\Agent\Model\Config;
use MageWatch\Agent\Model\PayloadBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PayloadBuilderTest extends TestCase
{
    private Config&MockObject $config;
    private Clock&MockObject $clock;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->clock = $this->createMock(Clock::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getSiteToken')->willReturn('secret-token');
        $this->clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-03T10:05:00+00:00'));
    }

    public function testBuildAssemblesBasePayloadAndMergesCollectorResults(): void
    {
        $this->config->method('isCollectorEnabled')->willReturn(true);

        $indexerCollector = $this->createCollector('indexer', ['indexers' => [['id' => 'catalog_product_price']]]);
        $cronCollector = $this->createCollector('cron', ['cron' => ['schedule_rows' => 100]]);

        $pool = new CollectorPool([$indexerCollector, $cronCollector]);
        $builder = new PayloadBuilder($this->config, $pool, $this->clock, $this->logger);

        $payload = $builder->build();

        $this->assertSame('1.0.1', $payload['agent_version']);
        $this->assertArrayNotHasKey('site_token', $payload);
        $this->assertSame('2026-07-03T10:05:00+00:00', $payload['collected_at']);
        $this->assertSame([['id' => 'catalog_product_price']], $payload['indexers']);
        $this->assertSame(['schedule_rows' => 100], $payload['cron']);
        $this->assertArrayNotHasKey('collector_errors', $payload);
    }

    public function testBuildSkipsDisabledCollectors(): void
    {
        $this->config->method('isCollectorEnabled')->willReturnMap([
            ['indexer', true],
            ['queue', false],
        ]);

        $indexerCollector = $this->createCollector('indexer', ['indexers' => []]);
        $queueCollector = $this->createMock(CollectorInterface::class);
        $queueCollector->method('getCode')->willReturn('queue');
        $queueCollector->expects($this->never())->method('collect');

        $pool = new CollectorPool([$indexerCollector, $queueCollector]);
        $builder = new PayloadBuilder($this->config, $pool, $this->clock, $this->logger);

        $payload = $builder->build();

        $this->assertArrayHasKey('indexers', $payload);
        $this->assertArrayNotHasKey('queues', $payload);
    }

    public function testBuildIsolatesFailingCollectorAndKeepsOthers(): void
    {
        $this->config->method('isCollectorEnabled')->willReturn(true);

        $goodCollector = $this->createCollector('system', ['system' => ['disk_free_bytes' => 1]]);

        $failingCollector = $this->createMock(CollectorInterface::class);
        $failingCollector->method('getCode')->willReturn('order_stats');
        $failingCollector->method('collect')->willThrowException(new \RuntimeException('DB unavailable'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('order_stats'));

        $pool = new CollectorPool([$goodCollector, $failingCollector]);
        $builder = new PayloadBuilder($this->config, $pool, $this->clock, $this->logger);

        $payload = $builder->build();

        $this->assertSame(['disk_free_bytes' => 1], $payload['system']);
        $this->assertArrayNotHasKey('orders_hourly', $payload);
        $this->assertCount(1, $payload['collector_errors']);
        $this->assertStringContainsString('order_stats', $payload['collector_errors'][0]);
        $this->assertStringContainsString('DB unavailable', $payload['collector_errors'][0]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function createCollector(string $code, array $result): CollectorInterface&MockObject
    {
        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('getCode')->willReturn($code);
        $collector->method('collect')->willReturn($result);

        return $collector;
    }
}
