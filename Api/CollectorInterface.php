<?php

declare(strict_types=1);

namespace MageWatch\Agent\Api;

/**
 * Contract for a single read-only metrics collector.
 *
 * Implementations must never modify store data and must not throw on
 * expected/recoverable conditions (e.g. missing optional table) - return
 * an empty result instead. Unexpected exceptions are caught by the
 * CollectorPool and isolated per-collector.
 */
interface CollectorInterface
{
    /**
     * Short machine-readable identifier, also used as the payload section key.
     */
    public function getCode(): string;

    /**
     * Collect metrics and return them as a plain, JSON-serializable array.
     *
     * @return array<string, mixed>
     */
    public function collect(): array;
}
