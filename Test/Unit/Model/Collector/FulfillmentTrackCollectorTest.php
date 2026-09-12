<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use DateTimeImmutable;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\Collector\FulfillmentTrackCollector;
use PHPUnit\Framework\TestCase;

class FulfillmentTrackCollectorTest extends TestCase
{
    public function test_code(): void
    {
        $collector = new FulfillmentTrackCollector(
            $this->createMock(ResourceConnection::class),
            $this->createMock(Clock::class),
        );

        $this->assertSame('fulfillment', $collector->getCode());
    }

    public function test_empty_when_tables_missing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $clock = $this->createMock(Clock::class);

        $this->assertSame(
            ['fulfillment' => ['window_days' => 90, 'tracks' => []]],
            (new FulfillmentTrackCollector($resource, $clock))->collect(),
        );
    }

    public function test_sends_magento_tracks_without_customer_fields(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            [
                'track_number' => 'PQ6C8N0732237700108005X',
                'carrier_code' => 'custom',
                'carrier_title' => 'Correos',
                'track_created_at' => '2026-05-27 14:22:19',
                'shipped_at' => '2026-05-27 14:22:19',
                'increment_id' => '000000036',
                'order_status' => 'complete',
                'order_total' => 11.94,
                'currency' => 'EUR',
                'ship_country' => 'ES',
                'ship_region' => 'Alicante',
                'ship_city' => 'Alicante',
                'ship_postcode' => '03001',
            ],
        ]);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-10 12:00:00'));

        $payload = (new FulfillmentTrackCollector($resource, $clock))->collect();
        $track = $payload['fulfillment']['tracks'][0];

        $this->assertSame('000000036', $track['increment_id']);
        $this->assertSame('PQ6C8N0732237700108005X', $track['track_number']);
        $this->assertSame('Correos', $track['carrier_title']);
        $this->assertSame('ES', $track['ship_country']);
        $this->assertSame('Alicante', $track['ship_region']);
        $this->assertSame('03001', $track['ship_postcode']);
        $this->assertArrayNotHasKey('customer_email', $track);
        $this->assertArrayNotHasKey('customer_name', $track);
        $this->assertArrayNotHasKey('street', $track);
        $this->assertArrayNotHasKey('telephone', $track);
        $this->assertArrayNotHasKey('packlink_reference', $track);
    }
}
