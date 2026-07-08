<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\LogOffset;

use MageWatch\Agent\Model\LogOffset\InitialLogOffset;
use PHPUnit\Framework\TestCase;

class InitialLogOffsetTest extends TestCase
{
    public function test_returns_stored_offset_when_already_bootstrapped(): void
    {
        $this->assertSame(5_000_000, InitialLogOffset::resolve(5_000_000, 20_000_000, 1_700_000_000));
    }

    public function test_starts_near_last_week_on_first_sight_of_old_large_file(): void
    {
        $now = 1_700_000_000;
        $mtime = $now - (365 * 86_400);
        $size = 100_000_000;

        $offset = InitialLogOffset::resolve(0, $size, $mtime, $now);

        $window = $size - $offset;
        $this->assertGreaterThan(0, $offset);
        $this->assertLessThan($size, $offset);
        $this->assertLessThanOrEqual(InitialLogOffset::MAX_WINDOW_BYTES, $window);
    }

    public function test_reads_entire_young_file_on_first_sight(): void
    {
        $now = 1_700_000_000;
        $mtime = $now - 86_400;
        $size = 50_000;

        $this->assertSame(0, InitialLogOffset::resolve(0, $size, $mtime, $now));
    }

    public function test_caps_window_bytes_on_very_large_files(): void
    {
        $now = 1_700_000_000;
        $mtime = $now - (30 * 86_400);
        $size = 500_000_000;

        $offset = InitialLogOffset::resolve(0, $size, $mtime, $now);

        $this->assertSame($size - InitialLogOffset::MAX_WINDOW_BYTES, $offset);
    }
}
