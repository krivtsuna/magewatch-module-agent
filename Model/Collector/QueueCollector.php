<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

/**
 * Reports per-queue backlog for DB-backed message queues.
 *
 * If the DB queue tables don't exist (e.g. the store uses RabbitMQ only),
 * this collector gracefully reports an empty list rather than failing -
 * RabbitMQ backlog introspection is out of scope for v1.
 */
class QueueCollector implements CollectorInterface
{
    private const CODE = 'queue';

    private const STATUS_NEW = 2;
    private const STATUS_IN_PROGRESS = 3;

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $statusTable = $this->resourceConnection->getTableName('queue_message_status');
        $queueTable = $this->resourceConnection->getTableName('queue');

        if (!$connection->isTableExists($statusTable) || !$connection->isTableExists($queueTable)) {
            return ['queues' => []];
        }

        $select = $connection->select()
            ->from(['qms' => $statusTable], ['status', 'cnt' => new Expression('COUNT(*)')])
            ->joinInner(['q' => $queueTable], 'q.id = qms.queue_id', ['name'])
            ->where('qms.status IN (?)', [self::STATUS_NEW, self::STATUS_IN_PROGRESS])
            ->group(['q.name', 'qms.status']);

        $byQueue = [];
        foreach ($connection->fetchAll($select) as $row) {
            $name = (string) $row['name'];
            $byQueue[$name] ??= ['name' => $name, 'new' => 0, 'in_progress' => 0];

            if ((int) $row['status'] === self::STATUS_NEW) {
                $byQueue[$name]['new'] = (int) $row['cnt'];
            } elseif ((int) $row['status'] === self::STATUS_IN_PROGRESS) {
                $byQueue[$name]['in_progress'] = (int) $row['cnt'];
            }
        }

        return ['queues' => array_values($byQueue)];
    }
}
