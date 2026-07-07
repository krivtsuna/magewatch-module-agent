<?php

declare(strict_types=1);

namespace MageWatch\Agent\Cron;

use MageWatch\Agent\Model\Config;
use MageWatch\Agent\Model\PayloadBuilder;
use MageWatch\Agent\Model\RemoteConfigSync;
use MageWatch\Agent\Model\Transport\HttpClient;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects health metrics and pushes them to the MageWatch SaaS endpoint.
 *
 * Must never throw: an uncaught exception here would break the shared
 * cron scheduler process. Every failure path is caught and logged instead.
 */
class CollectAndSend
{
    private const LAST_DELIVERY_CACHE_KEY = 'magewatch_last_delivery_at';

    public function __construct(
        private readonly Config $config,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly HttpClient $httpClient,
        private readonly RemoteConfigSync $remoteConfigSync,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->run();
        } catch (Throwable $e) {
            $this->logger->error(sprintf('MageWatch agent run failed: %s', $e->getMessage()));
        }
    }

    private function run(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $endpointUrl = $this->config->getEndpointUrl();
        $siteToken = $this->config->getSiteToken();

        if (!$endpointUrl || !$siteToken) {
            $this->logger->warning('MageWatch agent is enabled but endpoint URL or site token is not configured.');

            return;
        }

        $this->remoteConfigSync->sync();

        if ($this->config->isMonitoringPaused()) {
            $this->logger->info('MageWatch monitoring is paused remotely — skipping payload delivery.');

            return;
        }

        $intervalMinutes = $this->config->getHeartbeatIntervalMinutes();
        if ($intervalMinutes > 1) {
            $lastDelivery = $this->cache->load(self::LAST_DELIVERY_CACHE_KEY);
            if ($lastDelivery !== false && (time() - (int) $lastDelivery) < ($intervalMinutes * 60)) {
                return;
            }
        }

        $payload = $this->payloadBuilder->build();
        $result = $this->httpClient->send($endpointUrl, $siteToken, $payload);

        if (!$result->isSuccess()) {
            $this->logger->warning(sprintf(
                'MageWatch payload delivery failed (status: %s): %s',
                $result->getStatusCode() !== null ? (string) $result->getStatusCode() : 'n/a',
                $result->getErrorMessage() ?? $result->getResponseBody() ?? 'unknown error'
            ));
        } else {
            $this->cache->save((string) time(), self::LAST_DELIVERY_CACHE_KEY, [], 86400);
            $this->logger->info(sprintf(
                'MageWatch payload delivered successfully (HTTP %d)',
                $result->getStatusCode() ?? 200
            ));
        }
    }
}
