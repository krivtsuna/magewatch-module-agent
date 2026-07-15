<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed reader over MageWatch Agent system.xml configuration.
 */
class Config
{
    private const XML_PATH_ENABLED = 'magewatch/agent/enabled';
    private const XML_PATH_ENDPOINT_URL = 'magewatch/agent/endpoint_url';
    private const XML_PATH_SITE_TOKEN = 'magewatch/agent/site_token';
    private const XML_PATH_STUCK_CRON_THRESHOLD_MINUTES = 'magewatch/agent/stuck_cron_threshold_minutes';

    private const XML_PATH_COLLECTOR_PREFIX = 'magewatch/collectors/';

    private const XML_PATH_RUM_ENABLED = 'magewatch/agent/rum_enabled';

    private const REMOTE_CONFIG_CACHE_KEY = 'magewatch_remote_config';

    private const DEFAULT_STUCK_CRON_THRESHOLD_MINUTES = 30;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CacheInterface $cache
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function isMonitoringPaused(): bool
    {
        return (bool) ($this->getRemoteConfig()['monitoring_paused'] ?? false);
    }

    public function getEndpointUrl(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ENDPOINT_URL, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);

        return $value !== null ? (string) $value : null;
    }

    public function getSiteToken(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SITE_TOKEN, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    public function getStuckCronThresholdMinutes(): int
    {
        $remote = $this->getRemoteConfig();
        if (isset($remote['cron_stuck_threshold'])) {
            $minutes = (int) $remote['cron_stuck_threshold'];

            return $minutes > 0 ? $minutes : self::DEFAULT_STUCK_CRON_THRESHOLD_MINUTES;
        }

        $value = $this->scopeConfig->getValue(
            self::XML_PATH_STUCK_CRON_THRESHOLD_MINUTES,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        $minutes = $value !== null ? (int) $value : self::DEFAULT_STUCK_CRON_THRESHOLD_MINUTES;

        return $minutes > 0 ? $minutes : self::DEFAULT_STUCK_CRON_THRESHOLD_MINUTES;
    }

    public function getHeartbeatIntervalMinutes(): int
    {
        $remote = $this->getRemoteConfig();
        $minutes = (int) ($remote['heartbeat_interval_minutes'] ?? 1);

        return $minutes > 0 ? $minutes : 1;
    }

    public function isCollectorEnabled(string $collectorCode): bool
    {
        $remote = $this->getRemoteConfig();
        if (isset($remote['collectors'][$collectorCode])) {
            return (bool) $remote['collectors'][$collectorCode];
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_COLLECTOR_PREFIX . $collectorCode,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
    }

    public function isRumEnabled(): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_RUM_ENABLED, ScopeConfigInterface::SCOPE_TYPE_DEFAULT)) {
            return false;
        }

        $remote = $this->getRemoteConfig();

        if (array_key_exists('rum_enabled', $remote)) {
            return (bool) $remote['rum_enabled'];
        }

        // Last-known remote config still has a key — keep RUM on when SaaS sync is temporarily blocked.
        return $this->getRumPublicKey() !== null;
    }

    public function getRumPublicKey(): ?string
    {
        $remote = $this->getRemoteConfig();
        $key = $remote['rum_public_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSecurityPatchChecks(): array
    {
        $remote = $this->getRemoteConfig();
        $checks = $remote['security_patch_checks'] ?? [];

        if (!is_array($checks)) {
            return [];
        }

        $normalized = [];
        foreach ($checks as $check) {
            if (is_array($check)) {
                $normalized[] = $check;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRemoteConfig(): array
    {
        $json = $this->cache->load(self::REMOTE_CONFIG_CACHE_KEY);
        if ($json === false || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
