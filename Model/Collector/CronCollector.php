<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

/**
 * Reports cron health: stuck jobs, missed/error counts in the last hour,
 * schedule table bloat, and dead-cron detection via last successful run.
 */
class CronCollector implements CollectorInterface
{
    private const CODE = 'cron';

    private const STATUS_RUNNING = 'running';
    private const STATUS_MISSED = 'missed';
    private const STATUS_ERROR = 'error';
    private const STATUS_SUCCESS = 'success';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly Clock $clock
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cron_schedule');
        $now = $this->clock->now();

        return [
            'cron' => [
                'stuck' => $this->getStuckJobs($connection, $table, $now),
                'missed_last_hour' => $this->getStatusCountsByJob($connection, $table, self::STATUS_MISSED, $now),
                'errors_last_hour' => $this->getStatusCountsByJob($connection, $table, self::STATUS_ERROR, $now),
                'schedule_rows' => $this->getScheduleRowCount($connection, $table),
                'last_success_at' => $this->getLastSuccessAt($connection, $table),
            ],
        ];
    }

    /**
     * @return array<int, array{job_code: string, executed_at: string}>
     */
    private function getStuckJobs(AdapterInterface $connection, string $table, \DateTimeImmutable $now): array
    {
        $thresholdMinutes = $this->config->getStuckCronThresholdMinutes();
        $cutoff = $now->modify(sprintf('-%d minutes', $thresholdMinutes))->format('Y-m-d H:i:s');

        $select = $connection->select()
            ->from($table, ['job_code', 'executed_at'])
            ->where('status = ?', self::STATUS_RUNNING)
            ->where('executed_at IS NOT NULL')
            ->where('executed_at < ?', $cutoff);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[] = [
                'job_code' => (string) $row['job_code'],
                'executed_at' => $this->formatDate((string) $row['executed_at']),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{job_code: string, count: int}>
     */
    private function getStatusCountsByJob(
        AdapterInterface $connection,
        string $table,
        string $status,
        \DateTimeImmutable $now
    ): array {
        $since = $now->modify('-1 hour')->format('Y-m-d H:i:s');

        $select = $connection->select()
            ->from($table, ['job_code', 'cnt' => new Expression('COUNT(*)')])
            ->where('status = ?', $status)
            ->where('scheduled_at >= ?', $since)
            ->group('job_code');

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[] = [
                'job_code' => (string) $row['job_code'],
                'count' => (int) $row['cnt'],
            ];
        }

        return $result;
    }

    private function getScheduleRowCount(AdapterInterface $connection, string $table): int
    {
        $select = $connection->select()->from($table, ['cnt' => new Expression('COUNT(*)')]);

        return (int) $connection->fetchOne($select);
    }

    private function getLastSuccessAt(AdapterInterface $connection, string $table): ?string
    {
        $select = $connection->select()
            ->from($table, ['last' => new Expression('MAX(finished_at)')])
            ->where('status = ?', self::STATUS_SUCCESS);

        $value = $connection->fetchOne($select);

        return $value ? $this->formatDate((string) $value) : null;
    }

    private function formatDate(string $mysqlDatetime): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDatetime, new \DateTimeZone('UTC'));

        return $date !== false ? $date->format(DATE_ATOM) : $mysqlDatetime;
    }
}
