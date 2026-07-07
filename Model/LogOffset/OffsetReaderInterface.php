<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\LogOffset;

/**
 * Persists the last-read byte offset per log file so LogCollector only
 * counts lines written since the previous cron run.
 */
interface OffsetReaderInterface
{
    public function getOffset(string $filePath): int;

    public function setOffset(string $filePath, int $offset): void;
}
