<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * How often a collector should run a live collect() on the 5-minute heartbeat.
 *
 * SaaS ingest stores each snapshot as a full replace, so skipped collectors
 * still ship their last cached section (see CollectorResultCache).
 */
class CollectorCadence
{
    public const CATALOG_HEALTH_MINUTES = 60;

    public const COMPOSER_MINUTES = 60;

    public const REPORT_MINUTES = 15;

    /**
     * @var array<string, int>
     */
    private const MINUTES = [
        'catalog_health' => self::CATALOG_HEALTH_MINUTES,
        'composer' => self::COMPOSER_MINUTES,
        'report' => self::REPORT_MINUTES,
    ];

    public function ttlSeconds(string $code): int
    {
        $minutes = self::MINUTES[$code] ?? 0;

        return $minutes > 0 ? $minutes * 60 : 0;
    }

    /**
     * Magento compiled DI serializes this via var_export().
     *
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self();
    }
}
