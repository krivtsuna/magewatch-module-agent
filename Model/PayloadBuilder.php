<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assembles the final JSON-serializable payload from all enabled collectors.
 *
 * Each collector runs in isolation: a failing collector is logged and
 * omitted from the payload, the rest still ship.
 */
class PayloadBuilder
{
    public const AGENT_VERSION = '1.2.3';

    public function __construct(
        private readonly Config $config,
        private readonly CollectorPool $collectorPool,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $payload = [
            'agent_version' => self::AGENT_VERSION,
            'collected_at' => $this->clock->now()->format(DATE_ATOM),
        ];

        $collectorErrors = [];

        foreach ($this->collectorPool->getCollectors() as $collector) {
            $code = $collector->getCode();

            if (!$this->config->isCollectorEnabled($code)) {
                continue;
            }

            try {
                $result = $collector->collect();
                $payload = array_merge($payload, $result);
            } catch (Throwable $e) {
                $message = sprintf('%s: %s', $code, $e->getMessage());
                $this->logger->warning(sprintf('MageWatch collector "%s" failed: %s', $code, $e->getMessage()));
                $collectorErrors[] = $message;
            }
        }

        if ($collectorErrors !== []) {
            $payload['collector_errors'] = $collectorErrors;
        }

        return $payload;
    }

    /**
     * Lightweight payload for the admin "Send Test Ping" button — skips slow
     * collectors (storefront probes, pub/ scans) so the request returns quickly.
     *
     * @return array<string, mixed>
     */
    public function buildTestPing(): array
    {
        return [
            'agent_version' => self::AGENT_VERSION,
            'collected_at' => $this->clock->now()->format(DATE_ATOM),
            'test_ping' => true,
        ];
    }
}
