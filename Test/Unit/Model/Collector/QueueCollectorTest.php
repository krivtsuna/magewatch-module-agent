<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use MageWatch\Agent\Model\Collector\QueueCollector;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QueueCollectorTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private QueueCollector $collector;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->select = $this->createMock(Select::class);

        $this->select->method('from')->willReturnSelf();
        $this->select->method('joinInner')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->select->method('group')->willReturnSelf();

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
        $this->connection->method('select')->willReturn($this->select);

        $this->collector = new QueueCollector($this->resourceConnection);
    }

    public function testGetCode(): void
    {
        $this->assertSame('queue', $this->collector->getCode());
    }

    public function testCollectReturnsEmptyQueuesWhenTablesMissing(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);

        $result = $this->collector->collect();

        $this->assertSame(['queues' => []], $result);
    }

    public function testCollectGroupsBacklogByQueueName(): void
    {
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchAll')->willReturn([
            ['name' => 'async.operations.all', 'status' => 2, 'cnt' => '12'],
            ['name' => 'async.operations.all', 'status' => 3, 'cnt' => '1'],
            ['name' => 'sales.rule.update.coupon.usage', 'status' => 2, 'cnt' => '0'],
        ]);

        $result = $this->collector->collect();

        $this->assertSame([
            'queues' => [
                ['name' => 'async.operations.all', 'new' => 12, 'in_progress' => 1],
                ['name' => 'sales.rule.update.coupon.usage', 'new' => 0, 'in_progress' => 0],
            ],
        ], $result);
    }
}
