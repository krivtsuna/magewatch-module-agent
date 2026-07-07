<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Transport;

use Magento\Framework\HTTP\Client\CurlFactory;
use Throwable;

/**
 * Sends the assembled payload to the configured MageWatch SaaS endpoint.
 *
 * Uses Magento's built-in Curl HTTP client (no external Composer dependency).
 * Never throws: connection/transport failures are captured in the
 * returned TransportResult so callers can log and move on.
 */
class HttpClient
{
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TOTAL_TIMEOUT_SECONDS = 10;

    public function __construct(private readonly CurlFactory $curlFactory)
    {
    }

    public function send(string $url, string $bearerToken, array $payload): TransportResult
    {
        $curl = $this->curlFactory->create();

        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setTimeout(self::TOTAL_TIMEOUT_SECONDS);

        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('Authorization', 'Bearer ' . $bearerToken);

        try {
            $curl->post($url, (string) json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            return new TransportResult(false, null, null, $e->getMessage());
        }

        $status = (int) $curl->getStatus();
        $body = (string) $curl->getBody();

        return new TransportResult($status >= 200 && $status < 300, $status, $body, null);
    }

    public function get(string $url, string $bearerToken): TransportResult
    {
        $curl = $this->curlFactory->create();

        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setTimeout(self::TOTAL_TIMEOUT_SECONDS);

        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('Authorization', 'Bearer ' . $bearerToken);

        try {
            $curl->get($url);
        } catch (Throwable $e) {
            return new TransportResult(false, null, null, $e->getMessage());
        }

        $status = (int) $curl->getStatus();
        $body = (string) $curl->getBody();

        return new TransportResult($status >= 200 && $status < 300, $status, $body, null);
    }
}
