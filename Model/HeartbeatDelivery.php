<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use MageWatch\Agent\Model\Transport\HttpClient;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared HTTPS delivery for lightweight pings and full metric payloads.
 */
class HeartbeatDelivery
{
    private const LAST_PING_CACHE_KEY = 'magewatch_last_ping_at';

    private const LAST_FULL_CACHE_KEY = 'magewatch_last_full_at';

    private const LAST_MAINTENANCE_CACHE_KEY = 'magewatch_last_maintenance_state';

    public function __construct(
        private readonly Config $config,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly HttpClient $httpClient,
        private readonly RemoteConfigSync $remoteConfigSync,
        private readonly CacheInterface $cache,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly LoggerInterface $logger
    ) {
    }

    public function sendPing(): void
    {
        if (!$this->prepareDelivery()) {
            return;
        }

        $maintenanceOn = $this->maintenanceMode->isOn();
        $stateChanged = $this->maintenanceStateChanged($maintenanceOn);
        $pingPayload = array_merge(
            $this->payloadBuilder->buildHeartbeatPing(),
            ['maintenance_mode' => $maintenanceOn]
        );

        if ($stateChanged) {
            $this->deliver($pingPayload, self::LAST_PING_CACHE_KEY, 'ping-maintenance-change');
            $this->rememberMaintenanceState($maintenanceOn);

            return;
        }

        if ($this->isThrottled(self::LAST_PING_CACHE_KEY, $this->config->getHeartbeatIntervalMinutes())) {
            return;
        }

        $this->deliver($pingPayload, self::LAST_PING_CACHE_KEY, 'ping');
        $this->rememberMaintenanceState($maintenanceOn);
    }

    public function sendFull(): void
    {
        if (!$this->prepareDelivery()) {
            return;
        }

        $fullIntervalMinutes = max(5, $this->config->getHeartbeatIntervalMinutes());
        if ($this->isThrottled(self::LAST_FULL_CACHE_KEY, $fullIntervalMinutes)) {
            return;
        }

        $this->deliver(
            $this->payloadBuilder->build(),
            self::LAST_FULL_CACHE_KEY,
            'full'
        );
        $this->rememberMaintenanceState($this->maintenanceMode->isOn());
    }

    private function prepareDelivery(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        $endpointUrl = $this->config->getEndpointUrl();
        $siteToken = $this->config->getSiteToken();

        if (!$endpointUrl || !$siteToken) {
            $this->logger->warning('MageWatch agent is enabled but endpoint URL or site token is not configured.');

            return false;
        }

        $this->remoteConfigSync->sync();

        if ($this->config->isMonitoringPaused()) {
            $this->logger->info('MageWatch monitoring is paused remotely — skipping payload delivery.');

            return false;
        }

        return true;
    }

    private function isThrottled(string $cacheKey, int $intervalMinutes): bool
    {
        if ($intervalMinutes <= 1) {
            return false;
        }

        $lastDelivery = $this->cache->load($cacheKey);
        if ($lastDelivery === false) {
            return false;
        }

        return (time() - (int) $lastDelivery) < ($intervalMinutes * 60);
    }

    private function maintenanceStateChanged(bool $maintenanceOn): bool
    {
        $lastState = $this->cache->load(self::LAST_MAINTENANCE_CACHE_KEY);
        if ($lastState === false) {
            return false;
        }

        return ((string) $lastState === '1') !== $maintenanceOn;
    }

    private function rememberMaintenanceState(bool $maintenanceOn): void
    {
        $this->cache->save($maintenanceOn ? '1' : '0', self::LAST_MAINTENANCE_CACHE_KEY, [], 86400);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(array $payload, string $cacheKey, string $label): void
    {
        $endpointUrl = (string) $this->config->getEndpointUrl();
        $siteToken = (string) $this->config->getSiteToken();
        $result = $this->httpClient->send($endpointUrl, $siteToken, $payload);

        if (!$result->isSuccess()) {
            $this->logger->warning(sprintf(
                'MageWatch %s delivery failed (status: %s): %s',
                $label,
                $result->getStatusCode() !== null ? (string) $result->getStatusCode() : 'n/a',
                $result->getErrorMessage() ?? $result->getResponseBody() ?? 'unknown error'
            ));

            return;
        }

        $this->cache->save((string) time(), $cacheKey, [], 86400);
        $this->logger->info(sprintf(
            'MageWatch %s payload delivered successfully (HTTP %d)',
            $label,
            $result->getStatusCode() ?? 200
        ));
    }
}
