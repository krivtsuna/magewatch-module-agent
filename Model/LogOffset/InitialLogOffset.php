<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\LogOffset;

/**
 * On first sight of a log file (stored offset 0), skip ancient content and
 * start near the last ~7 days instead of reading from byte zero.
 */
class InitialLogOffset
{
    public const LOOKBACK_SECONDS = 604_800;

    public const MAX_WINDOW_BYTES = 10_000_000;

    public static function resolve(int $storedOffset, int $fileSize, int $fileMtime, ?int $now = null): int
    {
        if ($storedOffset !== 0) {
            return min($storedOffset, $fileSize);
        }

        if ($fileSize === 0) {
            return 0;
        }

        $now ??= time();
        $fileAge = max(1, $now - $fileMtime);
        $lookbackFraction = min(1.0, self::LOOKBACK_SECONDS / $fileAge);
        $windowBytes = (int) ceil($fileSize * $lookbackFraction);
        $windowBytes = min($windowBytes, self::MAX_WINDOW_BYTES);

        return max(0, $fileSize - $windowBytes);
    }
}
