<?php

declare(strict_types=1);

namespace MageWatch\Agent\Cron;

use MageWatch\Agent\Model\HeartbeatDelivery;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lightweight minute heartbeat — updates SaaS last_seen without running collectors.
 */
class HeartbeatPing
{
    public function __construct(
        private readonly HeartbeatDelivery $heartbeatDelivery,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->heartbeatDelivery->sendPing();
        } catch (Throwable $e) {
            $this->logger->error(sprintf('MageWatch heartbeat ping failed: %s', $e->getMessage()));
        }
    }
}
