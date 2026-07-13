<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\LogOffset;

/**
 * On first sight of a log file (stored offset 0), start at the tail instead of byte
 * zero so years of exception.log history do not flood the first heartbeat.
 *
 * Active logs always have a recent mtime, so time-based windows must not use mtime.
 */
class InitialLogOffset
{
    /** ~1 MiB tail on first run — roughly the last hour on a busy store. */
    public const INITIAL_TAIL_BYTES = 1_048_576;

    public static function resolve(int $storedOffset, int $fileSize, int $fileMtime, ?int $now = null): int
    {
        unset($fileMtime, $now);

        if ($storedOffset !== 0) {
            return min($storedOffset, $fileSize);
        }

        if ($fileSize === 0) {
            return 0;
        }

        $tailBytes = min(self::INITIAL_TAIL_BYTES, $fileSize);

        return $fileSize - $tailBytes;
    }
}
