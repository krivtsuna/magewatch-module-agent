<?php

declare(strict_types=1);

namespace MageWatch\Agent\Cron;

use MageWatch\Agent\Model\HeartbeatDelivery;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Full metric collection — runs every five minutes in the magewatch cron group.
 * Fat collectors (catalog, composer, reports) reuse a cached section; CMS is
 * hourly + incremental. See CollectorCadence.
 */
class CollectAndSend
{
    public function __construct(
        private readonly HeartbeatDelivery $heartbeatDelivery,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->heartbeatDelivery->sendFull();
        } catch (Throwable $e) {
            $this->logger->error(sprintf('MageWatch full collection failed: %s', $e->getMessage()));
        }
    }
}
