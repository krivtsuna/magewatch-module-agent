<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use MageWatch\Agent\Model\Collector\IndexerCollector;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\ConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IndexerCollectorTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private ConfigInterface&MockObject $indexerConfig;
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private IndexerCollector $collector;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->indexerConfig = $this->createMock(ConfigInterface::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->select = $this->createMock(Select::class);

        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->select->method('distinct')->willReturnSelf();
        $this->select->method('order')->willReturnSelf();
        $this->select->method('limit')->willReturnSelf();

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
        $this->connection->method('select')->willReturn($this->select);

        $this->collector = new IndexerCollector($this->resourceConnection, $this->indexerConfig);
    }

    public function testGetCode(): void
    {
        $this->assertSame('indexer', $this->collector->getCode());
    }

    public function testCollectReturnsScheduleModeIndexerWithBacklog(): void
    {
        $this->mockRegisteredIndexers(['catalog_product_price', 'catalog_category_product']);

        $this->connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                ['indexer_id' => 'catalog_product_price', 'status' => 'invalid', 'updated' => '2026-07-03 09:58:00'],
                ['indexer_id' => 'catalog_category_product', 'status' => 'valid', 'updated' => '2026-07-03 08:00:00'],
                ['indexer_id' => 'catalog_product_flat', 'status' => 'valid', 'updated' => '2024-01-01 00:00:00'],
            ],
            [
                ['view_id' => 'catalog_product_price', 'mode' => 'enabled', 'version_id' => 100],
            ]
        );

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchOne')->willReturn('200');
        $this->connection->method('fetchCol')->willReturn(array_fill(0, 3, 42));

        $result = $this->collector->collect();

        $this->assertArrayHasKey('indexers', $result);
        $this->assertCount(2, $result['indexers']);

        $priceIndexer = $result['indexers'][0];
        $this->assertSame('catalog_product_price', $priceIndexer['id']);
        $this->assertSame('invalid', $priceIndexer['status']);
        $this->assertSame('schedule', $priceIndexer['mode']);
        $this->assertSame(3, $priceIndexer['backlog']);
        $this->assertSame('2026-07-03T09:58:00+00:00', $priceIndexer['updated_at']);

        $categoryIndexer = $result['indexers'][1];
        $this->assertSame('catalog_category_product', $categoryIndexer['id']);
        $this->assertSame('realtime', $categoryIndexer['mode']);
        $this->assertArrayNotHasKey('backlog', $categoryIndexer);
    }

    public function testCollectSkipsBacklogWhenChangelogTableMissing(): void
    {
        $this->mockRegisteredIndexers(['catalog_product_price']);

        $this->connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                ['indexer_id' => 'catalog_product_price', 'status' => 'valid', 'updated' => '2026-07-03 09:58:00'],
            ],
            [
                ['view_id' => 'catalog_product_price', 'mode' => 'enabled', 'version_id' => 5],
            ]
        );

        $this->connection->method('isTableExists')->willReturn(false);

        $result = $this->collector->collect();

        $this->assertSame(0, $result['indexers'][0]['backlog']);
    }

    /**
     * @param  list<string>  $ids
     */
    private function mockRegisteredIndexers(array $ids): void
    {
        $indexers = [];
        foreach ($ids as $id) {
            $indexers[$id] = ['indexer_id' => $id];
        }

        $this->indexerConfig->method('getIndexers')->willReturn($indexers);
    }
}
