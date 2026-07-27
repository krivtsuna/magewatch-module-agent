<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Shared health vocabulary for per-check and overall rollup statuses.
 */
final class HealthStatus
{
    public const HEALTHY = 'healthy';

    public const DEGRADED = 'degraded';

    public const CRITICAL = 'critical';

    /** Security compromise — never capped by Tier-2 rollup rules. */
    public const COMPROMISED = 'compromised';

    /** @var list<string> */
    public const ALL = [
        self::HEALTHY,
        self::DEGRADED,
        self::CRITICAL,
        self::COMPROMISED,
    ];

    /**
     * @var array<string, int>
     */
    public const PRIORITY = [
        self::HEALTHY => 0,
        self::DEGRADED => 1,
        self::CRITICAL => 2,
        self::COMPROMISED => 3,
    ];

    public static function worse(string $a, string $b): string
    {
        $aPriority = self::PRIORITY[$a] ?? 0;
        $bPriority = self::PRIORITY[$b] ?? 0;

        return $aPriority >= $bPriority ? $a : $b;
    }
}
