<?php

declare(strict_types=1);

namespace MageWatch\Agent\Block;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use MageWatch\Agent\Model\Config;

/**
 * Injects the cache-safe attribution capture script on storefront pages.
 *
 * Everything this block emits is store-level configuration, so the markup is
 * identical for every visitor of a store view and the block stays cacheable
 * under FPC/Varnish. Per-visitor decisions (cookie consent) happen in the
 * script itself.
 */
class AttributionSnippet extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isAttributionEnabled();
    }

    public function getScriptUrl(): string
    {
        return $this->getViewFileUrl('MageWatch_Agent::js/attribution.js');
    }

    public function getWindowDays(): int
    {
        return $this->config->getAttributionWindowDays();
    }

    public function isCookieRestrictionModeEnabled(): bool
    {
        return $this->config->isCookieRestrictionModeEnabled();
    }

    /**
     * Cookie restriction consent is stored per website, so the script needs to
     * know which key to look for in `user_allowed_save_cookie`.
     */
    public function getWebsiteId(): string
    {
        try {
            return (string) $this->storeManager->getWebsite()->getId();
        } catch (NoSuchEntityException $e) {
            return '0';
        }
    }
}
