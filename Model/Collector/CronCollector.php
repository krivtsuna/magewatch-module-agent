<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

/**
 * Reports cron health: stuck jobs, missed/error counts in the last hour,
 * schedule table bloat, dead-cron detection, and per-group aggregates.
 *
 * Missed runs are grouped by job_code. Errors are grouped by job_code + message
 * so identical failures collapse into one row with a count.
 */
class CronCollector implements CollectorInterface
{
    private const CODE = 'cron';

    private const STATUS_RUNNING = 'running';
    private const STATUS_MISSED = 'missed';
    private const STATUS_ERROR = 'error';
    private const STATUS_SUCCESS = 'success';

    /** Cap payload size for noisy stores. */
    private const MAX_STATUS_ROWS = 40;

    private const MESSAGE_MAX_LEN = 280;

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
        $missed = $this->getMissedCountsByJob($connection, $table, $now);
        $errors = $this->getErrorCountsByJob($connection, $table, $now);
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
    private function getMissedCountsByJob(
        AdapterInterface $connection,
        string $table,
        \DateTimeImmutable $now
    ): array {
        $since = $now->modify('-1 hour')->format('Y-m-d H:i:s');

        $select = $connection->select()
            ->from($table, ['job_code', 'cnt' => new Expression('COUNT(*)')])
            ->where('status = ?', self::STATUS_MISSED)
            ->where('scheduled_at >= ?', $since)
            ->group('job_code')
            ->order(new Expression('COUNT(*) DESC'))
            ->limit(self::MAX_STATUS_ROWS);

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
     * Group identical failures (same job_code + same message text).
     *
     * @return array<int, array{job_code: string, count: int, message?: string}>
     */
    private function getErrorCountsByJob(
        AdapterInterface $connection,
        string $table,
        \DateTimeImmutable $now
    ): array {
        $since = $now->modify('-1 hour')->format('Y-m-d H:i:s');

        $select = $connection->select()
            ->from($table, [
                'job_code',
                'messages',
                'cnt' => new Expression('COUNT(*)'),
            ])
            ->where('status = ?', self::STATUS_ERROR)
            ->where('scheduled_at >= ?', $since)
            ->group(['job_code', 'messages'])
            ->order(new Expression('COUNT(*) DESC'))
            ->limit(self::MAX_STATUS_ROWS);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $entry = [
                'job_code' => (string) $row['job_code'],
                'count' => (int) $row['cnt'],
            ];
            $message = $this->normalizeMessage(isset($row['messages']) ? (string) $row['messages'] : '');
            if ($message !== null) {
                $entry['message'] = $message;
            }
            $result[] = $entry;
        }

        return $result;
    }

    private function normalizeMessage(string $raw): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($raw)) ?? '';
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > self::MESSAGE_MAX_LEN) {
            return mb_substr($normalized, 0, self::MESSAGE_MAX_LEN - 1).'…';
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{job_code: string, executed_at: string}>  $stuck
     * @param  array<int, array{job_code: string, count: int}>  $missed
     * @param  array<int, array{job_code: string, count: int, message?: string}>  $errors
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

        foreach ($this->discoverGroups() as $group) {
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
     * resolveGroup() only ever returns these three names — no need to DISTINCT
     * job_code on a bloated cron_schedule.
     *
     * @return list<string>
     */
    private function discoverGroups(): array
    {
        return ['default', 'index', 'magewatch'];
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
            ->from($table, ['last' => new Expression('MAX(finished_at)')])
            ->where('status = ?', self::STATUS_SUCCESS)
            ->where('finished_at IS NOT NULL');

        $this->applyGroupFilter($select, $group);

        $value = $connection->fetchOne($select);

        return $value ? $this->formatDate((string) $value) : null;
    }

    private function applyGroupFilter(Select $select, string $group): void
    {
        if ($group === 'magewatch') {
            $select->where("job_code LIKE 'magewatch_%' OR job_code = ''");

            return;
        }

        if ($group === 'index') {
            $select->where(
                "job_code LIKE '%indexer%'"
                ." OR job_code LIKE 'catalog_product_%'"
                ." OR job_code LIKE 'catalogsearch_%'"
                ." OR job_code LIKE 'inventory_%'"
            );

            return;
        }

        $select->where(
            "job_code NOT LIKE 'magewatch_%'"
            ." AND job_code != ''"
            ." AND job_code NOT LIKE '%indexer%'"
            ." AND job_code NOT LIKE 'catalog_product_%'"
            ." AND job_code NOT LIKE 'catalogsearch_%'"
            ." AND job_code NOT LIKE 'inventory_%'"
        );
    }

    private function getScheduleRowCount(AdapterInterface $connection, string $table): int
    {
        try {
            $estimate = $connection->fetchOne(
                'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            );
            if ($estimate !== false && $estimate !== null && $estimate !== '') {
                return (int) $estimate;
            }
        } catch (\Throwable) {
        }

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
