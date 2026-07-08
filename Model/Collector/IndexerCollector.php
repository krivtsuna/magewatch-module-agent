<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Reports indexer status and, for indexers running in "update by schedule"
 * mode, the pending changelog backlog (aligned with bin/magento indexer:status).
 */
class IndexerCollector implements CollectorInterface
{
    private const CODE = 'indexer';

    private const MVIEW_MODE_SCHEDULE = 'enabled';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly IndexerRegistry $indexerRegistry,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $connection = $this->resourceConnection->getConnection();

        $registeredIds = array_map(
            static fn ($indexer) => $indexer->getId(),
            $this->indexerRegistry->getIndexers()
        );

        $states = $this->fetchIndexerStates($connection);
        $views = $this->fetchMviewStates($connection);

        $indexers = [];
        foreach ($states as $indexerId => $state) {
            if (! in_array($indexerId, $registeredIds, true)) {
                continue;
            }

            $view = $views[$indexerId] ?? null;
            $isScheduled = $view !== null && $view['mode'] === self::MVIEW_MODE_SCHEDULE;

            $entry = [
                'id' => $indexerId,
                'status' => $state['status'],
                'mode' => $isScheduled ? 'schedule' : 'realtime',
                'updated_at' => $this->formatDate($state['updated']),
            ];

            if ($isScheduled) {
                $entry['backlog'] = $this->getChangelogBacklog(
                    $connection,
                    $indexerId,
                    (int) ($view['version_id'] ?? 0)
                );
            }

            $indexers[] = $entry;
        }

        return ['indexers' => $indexers];
    }

    /**
     * @return array<string, array{status: string, updated: ?string}>
     */
    private function fetchIndexerStates(AdapterInterface $connection): array
    {
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('indexer_state'), ['indexer_id', 'status', 'updated']);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(string) $row['indexer_id']] = [
                'status' => (string) $row['status'],
                'updated' => $row['updated'] !== null ? (string) $row['updated'] : null,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{mode: string, version_id: ?int}>
     */
    private function fetchMviewStates(AdapterInterface $connection): array
    {
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('mview_state'), ['view_id', 'mode', 'version_id']);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(string) $row['view_id']] = [
                'mode' => (string) $row['mode'],
                'version_id' => $row['version_id'] !== null ? (int) $row['version_id'] : null,
            ];
        }

        return $result;
    }

    /**
     * Match Magento CLI: distinct entity_ids with version_id between mview_state and changelog max.
     */
    private function getChangelogBacklog(AdapterInterface $connection, string $indexerId, int $lastVersionId): int
    {
        $changelogTable = $this->resourceConnection->getTableName($indexerId.'_cl');

        if (! $connection->isTableExists($changelogTable)) {
            return 0;
        }

        $maxVersionId = (int) $connection->fetchOne(
            $connection->select()
                ->from($changelogTable, ['version_id'])
                ->order('version_id DESC')
                ->limit(1)
        );

        if ($maxVersionId <= $lastVersionId) {
            return 0;
        }

        $entityIds = $connection->fetchCol(
            $connection->select()
                ->distinct(true)
                ->from($changelogTable, ['entity_id'])
                ->where('version_id > ?', $lastVersionId)
                ->where('version_id <= ?', $maxVersionId)
        );

        return count($entityIds);
    }

    private function formatDate(?string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === null || $mysqlDatetime === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDatetime, new \DateTimeZone('UTC'));

        return $date !== false ? $date->format(DATE_ATOM) : null;
    }
}
