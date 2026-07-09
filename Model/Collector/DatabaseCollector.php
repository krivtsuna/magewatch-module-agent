<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Throwable;

/**
 * Read-only MySQL health snapshot via Magento's existing DB connection.
 */
class DatabaseCollector implements CollectorInterface
{
    private const CODE = 'database';

    /** @var list<string> */
    private const STATUS_VARIABLES = [
        'Threads_running',
        'Threads_connected',
        'Slow_queries',
        'Questions',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $connection->fetchOne('SELECT 1');

            $status = $this->fetchGlobalStatus($connection);
            $longTransactions = $this->countLongTransactions($connection);

            return [
                'database' => [
                    'reachable' => true,
                    'threads_running' => $status['Threads_running'] ?? null,
                    'threads_connected' => $status['Threads_connected'] ?? null,
                    'slow_queries' => $status['Slow_queries'] ?? null,
                    'questions' => $status['Questions'] ?? null,
                    'long_transactions' => $longTransactions,
                ],
            ];
        } catch (Throwable) {
            return [
                'database' => [
                    'reachable' => false,
                    'threads_running' => null,
                    'threads_connected' => null,
                    'slow_queries' => null,
                    'questions' => null,
                    'long_transactions' => null,
                ],
            ];
        }
    }

    /**
     * @return array<string, int>
     */
    private function fetchGlobalStatus(AdapterInterface $connection): array
    {
        $placeholders = implode(',', array_fill(0, count(self::STATUS_VARIABLES), '?'));
        $rows = $connection->fetchPairs(
            'SHOW GLOBAL STATUS WHERE Variable_name IN ('.$placeholders.')',
            self::STATUS_VARIABLES,
        );

        $parsed = [];
        foreach ($rows as $name => $value) {
            $parsed[(string) $name] = (int) $value;
        }

        return $parsed;
    }

    private function countLongTransactions(AdapterInterface $connection): ?int
    {
        try {
            $count = $connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.innodb_trx
                 WHERE trx_started < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND)',
            );

            return $count !== false ? (int) $count : null;
        } catch (Throwable) {
            return null;
        }
    }
}
