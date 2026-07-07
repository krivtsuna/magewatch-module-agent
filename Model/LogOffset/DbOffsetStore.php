<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\LogOffset;

use Magento\Framework\App\ResourceConnection;

/**
 * DB-backed implementation, safe across multi-web-node deployments where
 * a per-node var/ directory would be unreliable for offset persistence.
 */
class DbOffsetStore implements OffsetReaderInterface
{
    private const TABLE = 'magewatch_log_offset';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function getOffset(string $filePath): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $select = $connection->select()
            ->from($table, ['byte_offset'])
            ->where('file_path = ?', $filePath);

        $value = $connection->fetchOne($select);

        return $value !== false ? (int) $value : 0;
    }

    public function setOffset(string $filePath, int $offset): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->insertOnDuplicate(
            $table,
            ['file_path' => $filePath, 'byte_offset' => $offset],
            ['byte_offset']
        );
    }
}
