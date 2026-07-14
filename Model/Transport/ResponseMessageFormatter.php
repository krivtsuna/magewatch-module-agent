<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Transport;

/**
 * Turns raw HTTP bodies into short admin-friendly messages.
 */
class ResponseMessageFormatter
{
    public static function forAdmin(?int $statusCode, ?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        if (self::isCloudflareChallenge($body)) {
            return 'Cloudflare blocked the request (managed challenge / Bot Fight Mode). '
                . 'WAF skip rules cannot bypass Bot Fight Mode. Use https://ingest.magewatch.io/api/v1/ingest '
                . '(DNS-only subdomain) in Magento agent settings, or disable Bot Fight Mode on magewatch.io.';
        }

        $trimmed = trim($body);
        if (strlen($trimmed) > 280) {
            $trimmed = substr($trimmed, 0, 277).'...';
        }

        if ($statusCode !== null) {
            return sprintf('HTTP %d: %s', $statusCode, $trimmed);
        }

        return $trimmed;
    }

    public static function isCloudflareChallenge(string $body): bool
    {
        return str_contains($body, 'Just a moment')
            || str_contains($body, 'challenge-platform')
            || str_contains($body, 'cf_chl_opt')
            || str_contains($body, 'Enable JavaScript and cookies to continue');
    }
}
