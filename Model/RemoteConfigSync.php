<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use MageWatch\Agent\Model\Transport\HttpClient;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Pulls monitoring settings from the MageWatch SaaS and caches them locally.
 */
class RemoteConfigSync
{
    private const CACHE_KEY = 'magewatch_remote_config';
    /** Keep last good config when sync fails (e.g. Cloudflare challenge). */
    private const CACHE_LIFETIME = 604800;
    private const CACHE_TAG = 'magewatch';

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function sync(): void
    {
        $endpointUrl = $this->config->getEndpointUrl();
        $siteToken = $this->config->getSiteToken();

        if (!$endpointUrl || !$siteToken) {
            return;
        }

        $configUrl = preg_replace('#/ingest$#', '/config', $endpointUrl) ?: $endpointUrl;

        $result = $this->httpClient->get($configUrl, $siteToken);

        if (!$result->isSuccess()) {
            $this->logger->warning(sprintf(
                'MageWatch remote config sync failed (status: %s)',
                $result->getStatusCode() !== null ? (string) $result->getStatusCode() : 'n/a'
            ));

            return;
        }

        $body = $result->getResponseBody();
        if ($body === null || $body === '') {
            return;
        }

        $this->cache->save($body, self::CACHE_KEY, [self::CACHE_TAG], self::CACHE_LIFETIME);
    }
}
