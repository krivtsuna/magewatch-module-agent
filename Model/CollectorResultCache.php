<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use Magento\Framework\App\CacheInterface;

/**
 * Remembers a collector's last payload section so the 5-minute heartbeat can
 * skip expensive work without wiping that section from the SaaS snapshot.
 */
class CollectorResultCache
{
    private const KEY_PREFIX = 'magewatch_collector_';

    public const CACHE_TAG = 'MAGEWATCH';

    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    /**
     * @param  callable(): array<string, mixed>  $producer
     * @return array<string, mixed>
     */
    public function remember(string $code, int $ttlSeconds, callable $producer, bool $force = false): array
    {
        if ($ttlSeconds <= 0) {
            return $producer();
        }

        if (! $force) {
            $cached = $this->load($code);
            if ($cached !== null) {
                return $cached;
            }
        }

        $fresh = $producer();
        $this->save($code, $fresh, $ttlSeconds);

        return $fresh;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load(string $code): ?array
    {
        $raw = $this->cache->load($this->key($code));
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function save(string $code, array $payload, int $ttlSeconds): void
    {
        $this->cache->save(
            (string) json_encode($payload),
            $this->key($code),
            [self::CACHE_TAG],
            $ttlSeconds
        );
    }

    private function key(string $code): string
    {
        return self::KEY_PREFIX.$code;
    }
}
