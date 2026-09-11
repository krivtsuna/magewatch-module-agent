<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\CollectorCadence;
use PHPUnit\Framework\TestCase;

class CollectorCadenceTest extends TestCase
{
    public function test_fat_collectors_have_ttl_light_ones_do_not(): void
    {
        $cadence = new CollectorCadence;

        $this->assertSame(3600, $cadence->ttlSeconds('catalog_health'));
        $this->assertSame(3600, $cadence->ttlSeconds('composer'));
        $this->assertSame(900, $cadence->ttlSeconds('report'));
        $this->assertSame(0, $cadence->ttlSeconds('cron'));
        $this->assertSame(0, $cadence->ttlSeconds('security'));
        $this->assertSame(0, $cadence->ttlSeconds('indexer'));
    }
}
