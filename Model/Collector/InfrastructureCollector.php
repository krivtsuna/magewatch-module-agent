<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;

/**
 * Probes Redis cache backend and OpenSearch/Elasticsearch cluster reachability.
 */
class InfrastructureCollector implements CollectorInterface
{
    private const CODE = 'infrastructure';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        return [
            'infrastructure' => [
                'redis' => $this->probeRedis(),
                'search' => $this->probeSearchEngine(),
            ],
        ];
    }

    /**
     * @return array{configured: bool, reachable: ?bool, host: ?string, port: ?int}
     */
    private function probeRedis(): array
    {
        $server = $this->deploymentConfig->get('cache/frontend/default/backend_options/server');
        if (! is_string($server) || $server === '') {
            return ['configured' => false, 'reachable' => null, 'host' => null, 'port' => null];
        }

        $port = (int) ($this->deploymentConfig->get('cache/frontend/default/backend_options/port') ?? 6379);

        return [
            'configured' => true,
            'reachable' => $this->canConnect($server, $port),
            'host' => $server,
            'port' => $port,
        ];
    }

    /**
     * @return array{engine: string, configured: bool, reachable: ?bool, host: ?string, port: ?int, cluster_status: ?string}
     */
    private function probeSearchEngine(): array
    {
        $engine = (string) $this->scopeConfig->getValue('catalog/search/engine');
        if ($engine === '' || $engine === 'mysql') {
            return [
                'engine' => $engine !== '' ? $engine : 'mysql',
                'configured' => false,
                'reachable' => null,
                'host' => null,
                'port' => null,
                'cluster_status' => null,
            ];
        }

        $hostname = (string) $this->scopeConfig->getValue('catalog/search/elasticsearch7_server_hostname');
        $port = (int) ($this->scopeConfig->getValue('catalog/search/elasticsearch7_server_port') ?: 9200);

        if ($hostname === '') {
            return [
                'engine' => $engine,
                'configured' => true,
                'reachable' => null,
                'host' => null,
                'port' => null,
                'cluster_status' => null,
            ];
        }

        $health = $this->clusterHealth("http://{$hostname}:{$port}/_cluster/health");

        return [
            'engine' => $engine,
            'configured' => true,
            'reachable' => $health['reachable'],
            'host' => $hostname,
            'port' => $port,
            'cluster_status' => $health['status'],
        ];
    }

    private function canConnect(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 2.0);

        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @return array{reachable: bool, status: ?string}
     */
    private function clusterHealth(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['reachable' => false, 'status' => null];
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return ['reachable' => false, 'status' => null];
        }

        $status = is_string($decoded['status'] ?? null) ? $decoded['status'] : null;
        $healthy = in_array($status, ['green', 'yellow'], true);

        return ['reachable' => $healthy, 'status' => $status];
    }
}
