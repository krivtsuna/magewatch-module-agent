<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\HealthRollup;
use MageWatch\Agent\Model\HealthStatus;
use PHPUnit\Framework\TestCase;

class HealthRollupTest extends TestCase
{
    public function testTier2CriticalIsCappedAtDegradedForOverall(): void
    {
        $rollup = new HealthRollup;
        $result = $rollup->build([
            'cron' => [
                'stuck' => [['job_code' => 'x', 'executed_at' => '2026-01-01']],
            ],
            'database' => ['reachable' => true],
        ]);

        $this->assertSame(HealthStatus::CRITICAL, $result['checks']['cron']);
        $this->assertSame(HealthStatus::DEGRADED, $result['status']);
    }

    public function testTier1CriticalWinsOverall(): void
    {
        $rollup = new HealthRollup;
        $result = $rollup->build([
            'database' => ['reachable' => false],
            'cron' => ['stuck' => []],
        ]);

        $this->assertSame(HealthStatus::CRITICAL, $result['checks']['database']);
        $this->assertSame(HealthStatus::CRITICAL, $result['status']);
    }

    public function testSecurityCompromisedIsNeverCapped(): void
    {
        $rollup = new HealthRollup;
        $result = $rollup->build([
            'security' => [
                'status' => HealthStatus::COMPROMISED,
                'content_integrity' => ['status' => HealthStatus::COMPROMISED],
            ],
        ]);

        $this->assertSame(HealthStatus::COMPROMISED, $result['checks']['security']);
        $this->assertSame(HealthStatus::COMPROMISED, $result['status']);
    }

    public function testCollectorErrorsMarkCheckCritical(): void
    {
        $rollup = new HealthRollup;
        $result = $rollup->build(
            ['database' => ['reachable' => true]],
            ['cron: boom']
        );

        $this->assertSame(HealthStatus::CRITICAL, $result['checks']['cron']);
        $this->assertSame(HealthStatus::DEGRADED, $result['status']);
    }
}
