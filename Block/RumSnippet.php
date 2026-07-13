<?php

declare(strict_types=1);

namespace MageWatch\Agent\Block;

use MageWatch\Agent\Model\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Injects a cache-safe RUM loader on storefront pages (FPC/Varnish friendly).
 *
 * Output is identical on every page (site-wide key + ingest URL only) so the block
 * stays cacheable. Page type is detected client-side in the SaaS-hosted v1.js.
 */
class RumSnippet extends Template
{
    /** Bust CDN/browser caches when the SaaS-hosted RUM script changes. */
    private const RUM_SCRIPT_VERSION = '3';

    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        if (!$this->config->isEnabled() || !$this->config->isRumEnabled()) {
            return false;
        }

        return $this->config->getRumPublicKey() !== null;
    }

    public function getPublicKey(): ?string
    {
        return $this->config->getRumPublicKey();
    }

    public function getScriptUrl(): string
    {
        $endpoint = $this->config->getEndpointUrl() ?? 'https://magewatch.io/api/v1/ingest';
        $base = preg_replace('#/api/v1/ingest$#', '', $endpoint) ?: 'https://magewatch.io';

        $query = http_build_query([
            'v' => self::RUM_SCRIPT_VERSION,
            'k' => $this->getPublicKey() ?? '',
            'u' => $this->getIngestBaseUrl(),
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim($base, '/') . '/rum/v1.js?' . $query;
    }

    public function getIngestBaseUrl(): string
    {
        $endpoint = $this->config->getEndpointUrl() ?? 'https://magewatch.io/api/v1/ingest';
        $base = preg_replace('#/api/v1/ingest$#', '', $endpoint) ?: 'https://magewatch.io';

        return rtrim($base, '/') . '/ingest/rum';
    }
}
