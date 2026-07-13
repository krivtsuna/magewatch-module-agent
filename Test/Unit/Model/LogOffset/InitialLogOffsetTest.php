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

    public function test_reads_only_tail_on_first_sight_of_large_file(): void
    {
        $size = 100_000_000;

        $offset = InitialLogOffset::resolve(0, $size, time(), time());

        $this->assertSame($size - InitialLogOffset::INITIAL_TAIL_BYTES, $offset);
    }

    public function test_reads_entire_small_file_on_first_sight(): void
    {
        $size = 50_000;

        $this->assertSame(0, InitialLogOffset::resolve(0, $size, time(), time()));
    }

    public function test_ignores_recent_mtime_on_active_log(): void
    {
        $now = time();
        $size = 80_000_000;

        $offset = InitialLogOffset::resolve(0, $size, $now - 30, $now);

        $this->assertSame($size - InitialLogOffset::INITIAL_TAIL_BYTES, $offset);
    }
}
