<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\MagentoErrorPageDetector;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Probes the storefront from the Magento server (origin) so MageWatch can
 * distinguish real outages from Cloudflare/WAF blocks on external checks.
 */
class StorefrontProbeCollector implements CollectorInterface
{
    private const CODE = 'storefront_probe';

    private const TIMEOUT_SECONDS = 10;

    private const BODY_READ_LIMIT = 65_536;

    private const USER_AGENT = 'MageWatch-Agent/1.0.10';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly MagentoErrorPageDetector $errorPageDetector,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $baseUrl = $this->resolveStorefrontUrl();
        if ($baseUrl === null) {
            return [
                'storefront_probe' => $this->emptyProbe('none'),
            ];
        }

        $homepage = $this->probe($baseUrl);
        $checkout = $this->probe($baseUrl.'/checkout');

        return [
            'storefront_probe' => [
                'homepage_ok' => $homepage['ok'],
                'homepage_status' => $homepage['status'],
                'homepage_ms' => $homepage['ms'],
                'homepage_magento_error' => $homepage['magento_error'],
                'checkout_ok' => $checkout['ok'],
                'checkout_status' => $checkout['status'],
                'checkout_ms' => $checkout['ms'],
                'checkout_magento_error' => $checkout['magento_error'],
                'probe_method' => $homepage['method'],
            ],
        ];
    }

    /**
     * @return array{
     *     homepage_ok: bool,
     *     homepage_status: ?int,
     *     homepage_ms: ?int,
     *     homepage_magento_error: bool,
     *     checkout_ok: bool,
     *     checkout_status: ?int,
     *     checkout_ms: ?int,
     *     checkout_magento_error: bool,
     *     probe_method: string
     * }
     */
    private function emptyProbe(string $method): array
    {
        return [
            'homepage_ok' => false,
            'homepage_status' => null,
            'homepage_ms' => null,
            'homepage_magento_error' => false,
            'checkout_ok' => false,
            'checkout_status' => null,
            'checkout_ms' => null,
            'checkout_magento_error' => false,
            'probe_method' => $method,
        ];
    }

    private function resolveStorefrontUrl(): ?string
    {
        try {
            $url = $this->storeManager->getStore()->getBaseUrl();
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($url) || $url === '' || ! str_starts_with($url, 'http')) {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * @return array{ok: bool, status: ?int, ms: ?int, method: string, magento_error: bool}
     */
    private function probe(string $url): array
    {
        $parsed = parse_url($url);
        if (! is_array($parsed) || empty($parsed['host'])) {
            return ['ok' => false, 'status' => null, 'ms' => null, 'method' => 'invalid', 'magento_error' => false];
        }

        $host = (string) $parsed['host'];
        $path = $parsed['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $scheme = ($parsed['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $port = $scheme === 'https' ? 443 : 80;

        $origin = $this->curlProbe($scheme, $host, $path, $port, true);
        if ($origin['ok'] || $origin['magento_error']) {
            $origin['method'] = 'origin';

            return $origin;
        }

        $direct = $this->curlProbe($scheme, $host, $path, $port, false);
        $direct['method'] = 'direct';

        return $direct;
    }

    /**
     * @return array{ok: bool, status: ?int, ms: ?int, method: string, magento_error: bool}
     */
    private function curlProbe(string $scheme, string $host, string $path, int $port, bool $viaLocalhost): array
    {
        if (! function_exists('curl_init')) {
            return ['ok' => false, 'status' => null, 'ms' => null, 'method' => 'unavailable', 'magento_error' => false];
        }

        $start = microtime(true);
        $targetUrl = "{$scheme}://{$host}{$path}";

        $ch = curl_init($targetUrl);
        if ($ch === false) {
            return ['ok' => false, 'status' => null, 'ms' => null, 'method' => 'error', 'magento_error' => false];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => [
                "Host: {$host}",
                'User-Agent: '.self::USER_AGENT,
                'Accept: text/html',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];

        if ($viaLocalhost) {
            $options[CURLOPT_RESOLVE] = ["{$host}:{$port}:127.0.0.1"];
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ms = (int) round((microtime(true) - $start) * 1000);
        $bodySample = is_string($body) ? mb_substr($body, 0, self::BODY_READ_LIMIT) : null;
        $magentoError = $this->errorPageDetector->isErrorPage($bodySample);
        $ok = $status >= 200 && $status < 400 && ! $magentoError;

        return [
            'ok' => $ok,
            'status' => $status > 0 ? $status : null,
            'ms' => $ms,
            'method' => $viaLocalhost ? 'origin' : 'direct',
            'magento_error' => $magentoError,
        ];
    }
}
