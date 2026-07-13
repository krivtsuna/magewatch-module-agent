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
 * schedule table bloat, dead-cron detection, and per-group aggregates.
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

        $stuck = $this->getStuckJobs($connection, $table, $now);
        $missed = $this->getStatusCountsByJob($connection, $table, self::STATUS_MISSED, $now);
        $errors = $this->getStatusCountsByJob($connection, $table, self::STATUS_ERROR, $now);
        $groups = $this->buildGroupStats($connection, $table, $now, $stuck, $missed, $errors);

        return [
            'cron' => [
                'stuck' => $stuck,
                'missed_last_hour' => $missed,
                'errors_last_hour' => $errors,
                'schedule_rows' => $this->getScheduleRowCount($connection, $table),
                'last_success_at' => $this->getLastSuccessAt($connection, $table),
                'groups' => $groups,
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

    /**
     * @param  array<int, array{job_code: string, executed_at: string}>  $stuck
     * @param  array<int, array{job_code: string, count: int}>  $missed
     * @param  array<int, array{job_code: string, count: int}>  $errors
     * @return array<int, array{group: string, last_success_at: ?string, missed_last_hour: int, errors_last_hour: int, stuck: int}>
     */
    private function buildGroupStats(
        AdapterInterface $connection,
        string $table,
        \DateTimeImmutable $now,
        array $stuck,
        array $missed,
        array $errors
    ): array {
        $groups = [];

        foreach ($this->discoverGroups($connection, $table) as $group) {
            $groups[$group] = [
                'group' => $group,
                'last_success_at' => $this->getLastSuccessAtForGroup($connection, $table, $group),
                'missed_last_hour' => 0,
                'errors_last_hour' => 0,
                'stuck' => 0,
            ];
        }

        foreach ($missed as $row) {
            $group = $this->resolveGroup((string) $row['job_code']);
            $groups[$group]['missed_last_hour'] += (int) $row['count'];
        }

        foreach ($errors as $row) {
            $group = $this->resolveGroup((string) $row['job_code']);
            $groups[$group]['errors_last_hour'] += (int) $row['count'];
        }

        foreach ($stuck as $row) {
            $group = $this->resolveGroup((string) $row['job_code']);
            $groups[$group]['stuck']++;
        }

        return array_values($groups);
    }

    /**
     * @return list<string>
     */
    private function discoverGroups(AdapterInterface $connection, string $table): array
    {
        $select = $connection->select()
            ->from($table, ['job_code'])
            ->distinct(true)
            ->limit(500);

        $groups = ['default', 'index', 'magewatch'];
        foreach ($connection->fetchCol($select) as $jobCode) {
            $groups[] = $this->resolveGroup((string) $jobCode);
        }

        return array_values(array_unique($groups));
    }

    private function resolveGroup(string $jobCode): string
    {
        $jobCode = strtolower(trim($jobCode));

        if ($jobCode === '' || str_starts_with($jobCode, 'magewatch_')) {
            return 'magewatch';
        }

        if (
            str_contains($jobCode, 'indexer')
            || str_starts_with($jobCode, 'catalog_product_')
            || str_starts_with($jobCode, 'catalogsearch_')
            || str_starts_with($jobCode, 'inventory_')
        ) {
            return 'index';
        }

        return 'default';
    }

    private function getLastSuccessAtForGroup(AdapterInterface $connection, string $table, string $group): ?string
    {
        $select = $connection->select()
            ->from($table, ['job_code', 'finished_at'])
            ->where('status = ?', self::STATUS_SUCCESS)
            ->where('finished_at IS NOT NULL')
            ->order('finished_at DESC')
            ->limit(2000);

        foreach ($connection->fetchAll($select) as $row) {
            if ($this->resolveGroup((string) $row['job_code']) === $group) {
                return $this->formatDate((string) $row['finished_at']);
            }
        }

        return null;
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
