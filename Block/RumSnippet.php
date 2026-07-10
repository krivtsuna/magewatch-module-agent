<?php

declare(strict_types=1);

namespace MageWatch\Agent\Block;

use MageWatch\Agent\Model\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * Injects a cache-safe RUM loader on storefront pages (FPC/Varnish friendly).
 */
class RumSnippet extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ?SecureHtmlRenderer $secureRenderer = null,
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

    public function getPageType(): string
    {
        $handles = $this->getLayout()->getUpdate()->getHandles();

        return match (true) {
            in_array('checkout_onepage_success', $handles, true) => 'success',
            in_array('checkout_index_index', $handles, true) => 'checkout',
            in_array('checkout_cart_index', $handles, true) => 'cart',
            in_array('catalog_product_view', $handles, true) => 'product',
            in_array('catalog_category_view', $handles, true) => 'category',
            in_array('cms_page_view', $handles, true),
            in_array('cms_index_index', $handles, true) => 'cms',
            default => 'other',
        };
    }

    public function getScriptUrl(): string
    {
        $endpoint = $this->config->getEndpointUrl() ?? 'https://magewatch.io/api/v1/ingest';
        $base = preg_replace('#/api/v1/ingest$#', '', $endpoint) ?: 'https://magewatch.io';

        return rtrim($base, '/') . '/rum/v1.js';
    }

    public function getIngestBaseUrl(): string
    {
        $endpoint = $this->config->getEndpointUrl() ?? 'https://magewatch.io/api/v1/ingest';
        $base = preg_replace('#/api/v1/ingest$#', '', $endpoint) ?: 'https://magewatch.io';

        return rtrim($base, '/') . '/ingest/rum';
    }

    public function getConfigScriptHtml(): string
    {
        $payload = json_encode([
            'k' => $this->getPublicKey(),
            'p' => $this->getPageType(),
            'u' => $this->getIngestBaseUrl(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $script = 'window.__mwRum=' . $payload . ';';

        if ($this->secureRenderer !== null) {
            return $this->secureRenderer->renderTag('script', [], $script, false);
        }

        return '<script>' . $script . '</script>';
    }
}
