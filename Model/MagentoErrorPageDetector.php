<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Detects Magento's generic production error pages in an HTTP response body.
 */
class MagentoErrorPageDetector
{
    /**
     * @var list<string>
     */
    private const MARKERS = [
        'an error has happened during application run',
        'there has been an error processing your request',
        'exception printing is disabled by default for security reasons',
        'error happens during the sync of media gallery',
    ];

    public function isErrorPage(?string $body): bool
    {
        if ($body === null || $body === '') {
            return false;
        }

        $normalized = strtolower($body);

        foreach (self::MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }
}
