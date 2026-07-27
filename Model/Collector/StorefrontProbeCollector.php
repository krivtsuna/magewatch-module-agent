<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\MagentoErrorPageDetector;
use MageWatch\Agent\Model\ProbeBlockDetector;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Probes the storefront homepage from the Magento server (origin) so MageWatch can
 * distinguish real outages from Cloudflare/WAF blocks on external checks.
 */
class StorefrontProbeCollector implements CollectorInterface
{
    private const CODE = 'storefront_probe';

    private const TIMEOUT_SECONDS = 10;

    private const BODY_READ_LIMIT = 65_536;

    private const USER_AGENT = 'MageWatch-Agent/1.1.0';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly MagentoErrorPageDetector $errorPageDetector,
        private readonly ProbeBlockDetector $probeBlockDetector,
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

        $payload = [
            'homepage_ok' => $homepage['ok'],
            'homepage_status' => $homepage['status'],
            'homepage_ms' => $homepage['ms'],
            'homepage_magento_error' => $homepage['magento_error'],
            'probe_method' => $homepage['method'],
        ];

        if (! $homepage['ok'] && ! $homepage['magento_error']) {
            if ($homepage['block_kind'] !== null) {
                $payload['homepage_block_kind'] = $homepage['block_kind'];
            }
            if ($homepage['body_excerpt'] !== null) {
                $payload['homepage_body_excerpt'] = $homepage['body_excerpt'];
            }
            if ($homepage['cf_ray'] !== null) {
                $payload['homepage_cf_ray'] = $homepage['cf_ray'];
            }
        }

        return [
            'storefront_probe' => $payload,
        ];
    }

    /**
     * @return array{
     *     homepage_ok: bool,
     *     homepage_status: ?int,
     *     homepage_ms: ?int,
     *     homepage_magento_error: bool,
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
     * @return array{
     *     ok: bool,
     *     status: ?int,
     *     ms: ?int,
     *     method: string,
     *     magento_error: bool,
     *     block_kind: ?string,
     *     body_excerpt: ?string,
     *     cf_ray: ?string
     * }
     */
    private function probe(string $url): array
    {
        $parsed = parse_url($url);
        if (! is_array($parsed) || empty($parsed['host'])) {
            return $this->failedProbe('invalid');
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
     * @return array{
     *     ok: bool,
     *     status: ?int,
     *     ms: ?int,
     *     method: string,
     *     magento_error: bool,
     *     block_kind: ?string,
     *     body_excerpt: ?string,
     *     cf_ray: ?string
     * }
     */
    private function failedProbe(string $method): array
    {
        return [
            'ok' => false,
            'status' => null,
            'ms' => null,
            'method' => $method,
            'magento_error' => false,
            'block_kind' => null,
            'body_excerpt' => null,
            'cf_ray' => null,
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     status: ?int,
     *     ms: ?int,
     *     method: string,
     *     magento_error: bool,
     *     block_kind: ?string,
     *     body_excerpt: ?string,
     *     cf_ray: ?string
     * }
     */
    private function curlProbe(string $scheme, string $host, string $path, int $port, bool $viaLocalhost): array
    {
        if (! function_exists('curl_init')) {
            return $this->failedProbe('unavailable');
        }

        $start = microtime(true);
        $targetUrl = "{$scheme}://{$host}{$path}";

        $ch = curl_init($targetUrl);
        if ($ch === false) {
            return $this->failedProbe('error');
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];

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
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                if (! str_contains($headerLine, ':')) {
                    return $len;
                }

                [$name, $value] = explode(':', $headerLine, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);

                return $len;
            },
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

        $block = ['kind' => null, 'excerpt' => null, 'cf_ray' => null];
        if (! $ok && ! $magentoError) {
            $block = $this->probeBlockDetector->analyze($bodySample, $responseHeaders);
            // Always keep an excerpt for failed non-Magento probes when possible.
            if ($block['excerpt'] === null) {
                $block['excerpt'] = $this->probeBlockDetector->excerpt($bodySample);
            }
            if ($block['kind'] === null && in_array($status, [401, 403, 429], true)) {
                $block['kind'] = 'waf';
            }
        }

        return [
            'ok' => $ok,
            'status' => $status > 0 ? $status : null,
            'ms' => $ms,
            'method' => $viaLocalhost ? 'origin' : 'direct',
            'magento_error' => $magentoError,
            'block_kind' => $block['kind'],
            'body_excerpt' => $block['excerpt'],
            'cf_ray' => $block['cf_ray'],
        ];
    }
}
