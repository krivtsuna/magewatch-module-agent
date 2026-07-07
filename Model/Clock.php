<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Thin wrapper around the current time so it can be mocked in tests.
 */
class Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
