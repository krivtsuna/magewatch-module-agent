<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Builds top-level health.status + health.checks from the assembled payload.
 *
 * Tier 1 (site-critical) collectors contribute their real status to overall.
 * Tier 2 (operational) critical is capped at degraded for overall rollup only —
 * per-check statuses in health.checks stay uncapped so SaaS alerts still fire.
 */
final class HealthRollup
{
    /**
     * Site-critical: storefront likely unavailable or severely broken.
     *
     * @var list<string>
     */
    private const TIER1 = [
        'database',
        'infrastructure',
        'system',
        'storefront_probe',
        'security',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $collectorErrors
     * @return array{status: string, checks: array<string, string>}
     */
    public function build(array $payload, array $collectorErrors = []): array
    {
        $checks = [];

        if (isset($payload['database']) && is_array($payload['database'])) {
            $checks['database'] = $this->databaseStatus($payload['database']);
        }
        if (isset($payload['infrastructure']) && is_array($payload['infrastructure'])) {
            $checks['infrastructure'] = $this->infrastructureStatus($payload['infrastructure']);
        }
        if (isset($payload['system']) && is_array($payload['system'])) {
            $checks['system'] = $this->systemStatus($payload['system'], $payload['magento'] ?? null);
        }
        if (isset($payload['storefront_probe']) && is_array($payload['storefront_probe'])) {
            $checks['storefront_probe'] = $this->storefrontProbeStatus($payload['storefront_probe']);
        }
        if (isset($payload['security']) && is_array($payload['security'])) {
            $checks['security'] = $this->securityStatus($payload['security']);
        }
        if (isset($payload['cron']) && is_array($payload['cron'])) {
            $checks['cron'] = $this->cronStatus($payload['cron']);
        }
        if (isset($payload['queues']) && is_array($payload['queues'])) {
            $checks['queue'] = $this->queueStatus($payload['queues']);
        }
        if (isset($payload['indexers']) && is_array($payload['indexers'])) {
            $checks['indexer'] = $this->indexerStatus($payload['indexers']);
        }

        foreach ($collectorErrors as $error) {
            $code = $this->collectorCodeFromError($error);
            if ($code === null) {
                continue;
            }
            $checks[$code] = HealthStatus::worse(
                $checks[$code] ?? HealthStatus::HEALTHY,
                HealthStatus::CRITICAL
            );
        }

        $overall = HealthStatus::HEALTHY;
        foreach ($checks as $name => $status) {
            $effective = $this->effectiveForOverall($name, $status);
            $overall = HealthStatus::worse($overall, $effective);
        }

        return [
            'status' => $overall,
            'checks' => $checks,
        ];
    }

    private function effectiveForOverall(string $collectorName, string $status): string
    {
        if ($status === HealthStatus::CRITICAL && ! in_array($collectorName, self::TIER1, true)) {
            return HealthStatus::DEGRADED;
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $database
     */
    private function databaseStatus(array $database): string
    {
        if (isset($database['status']) && is_string($database['status'])) {
            return $this->normalize($database['status']);
        }

        if (($database['reachable'] ?? true) === false) {
            return HealthStatus::CRITICAL;
        }

        $long = $database['long_transactions'] ?? 0;
        if (is_int($long) && $long > 0) {
            return HealthStatus::DEGRADED;
        }

        return HealthStatus::HEALTHY;
    }

    /**
     * @param  array<string, mixed>  $infrastructure
     */
    private function infrastructureStatus(array $infrastructure): string
    {
        if (isset($infrastructure['status']) && is_string($infrastructure['status'])) {
            return $this->normalize($infrastructure['status']);
        }

        $status = HealthStatus::HEALTHY;
        $redis = is_array($infrastructure['redis'] ?? null) ? $infrastructure['redis'] : [];
        $search = is_array($infrastructure['search'] ?? null) ? $infrastructure['search'] : [];

        if (($redis['configured'] ?? false) === true && ($redis['reachable'] ?? true) === false) {
            $status = HealthStatus::worse($status, HealthStatus::CRITICAL);
        }
        if (($search['configured'] ?? false) === true && ($search['reachable'] ?? true) === false) {
            $status = HealthStatus::worse($status, HealthStatus::CRITICAL);
        }
        if (($search['cluster_status'] ?? null) === 'red') {
            $status = HealthStatus::worse($status, HealthStatus::CRITICAL);
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $system
     * @param  mixed  $magento
     */
    private function systemStatus(array $system, mixed $magento): string
    {
        if (isset($system['status']) && is_string($system['status'])) {
            return $this->normalize($system['status']);
        }

        $status = HealthStatus::HEALTHY;
        $diskPercent = $system['disk_free_percent'] ?? null;
        if (is_numeric($diskPercent)) {
            if ((float) $diskPercent < 5.0) {
                $status = HealthStatus::worse($status, HealthStatus::CRITICAL);
            } elseif ((float) $diskPercent < 15.0) {
                $status = HealthStatus::worse($status, HealthStatus::DEGRADED);
            }
        }

        if (is_array($magento) && ($magento['maintenance'] ?? false) === true) {
            $status = HealthStatus::worse($status, HealthStatus::DEGRADED);
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function storefrontProbeStatus(array $probe): string
    {
        if (isset($probe['status']) && is_string($probe['status'])) {
            return $this->normalize($probe['status']);
        }

        if (($probe['homepage_magento_error'] ?? false) === true) {
            return HealthStatus::CRITICAL;
        }
        if (($probe['homepage_ok'] ?? true) === false) {
            return HealthStatus::CRITICAL;
        }

        return HealthStatus::HEALTHY;
    }

    /**
     * @param  array<string, mixed>  $security
     */
    private function securityStatus(array $security): string
    {
        if (isset($security['status']) && is_string($security['status'])) {
            return $this->normalize($security['status']);
        }

        $status = HealthStatus::HEALTHY;

        if (($security['unexpected_pub_php'] ?? []) !== []
            || ($security['core_pub_php_modified'] ?? []) !== []
            || ($security['suspicious_patterns'] ?? []) !== []
        ) {
            $status = HealthStatus::worse($status, HealthStatus::CRITICAL);
        }

        $content = is_array($security['content_integrity'] ?? null) ? $security['content_integrity'] : [];
        if (isset($content['status']) && is_string($content['status'])) {
            $status = HealthStatus::worse($status, $this->normalize($content['status']));
        }

        $admin = is_array($security['admin_security'] ?? null) ? $security['admin_security'] : [];
        if (isset($admin['status']) && is_string($admin['status'])) {
            $status = HealthStatus::worse($status, $this->normalize($admin['status']));
        }

        $hygiene = is_array($security['config_hygiene'] ?? null) ? $security['config_hygiene'] : [];
        if (isset($hygiene['status']) && is_string($hygiene['status'])) {
            $status = HealthStatus::worse($status, $this->normalize($hygiene['status']));
        }

        if (($security['new_admin_users'] ?? []) !== []) {
            $status = HealthStatus::worse($status, HealthStatus::DEGRADED);
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $cron
     */
    private function cronStatus(array $cron): string
    {
        if (isset($cron['status']) && is_string($cron['status'])) {
            return $this->normalize($cron['status']);
        }

        if (($cron['stuck'] ?? []) !== []) {
            return HealthStatus::CRITICAL;
        }
        if (($cron['errors_last_hour'] ?? []) !== []) {
            return HealthStatus::DEGRADED;
        }
        if (($cron['missed_last_hour'] ?? []) !== []) {
            return HealthStatus::DEGRADED;
        }

        return HealthStatus::HEALTHY;
    }

    /**
     * @param  list<array<string, mixed>>  $queues
     */
    private function queueStatus(array $queues): string
    {
        $total = 0;
        foreach ($queues as $queue) {
            if (! is_array($queue)) {
                continue;
            }
            $total += (int) ($queue['new'] ?? 0) + (int) ($queue['in_progress'] ?? 0);
        }

        if ($total > 5000) {
            return HealthStatus::CRITICAL;
        }
        if ($total > 500) {
            return HealthStatus::DEGRADED;
        }

        return HealthStatus::HEALTHY;
    }

    /**
     * @param  list<array<string, mixed>>  $indexers
     */
    private function indexerStatus(array $indexers): string
    {
        $status = HealthStatus::HEALTHY;
        foreach ($indexers as $indexer) {
            if (! is_array($indexer)) {
                continue;
            }
            $indexerStatus = (string) ($indexer['status'] ?? '');
            if ($indexerStatus === 'invalid') {
                $status = HealthStatus::worse($status, HealthStatus::CRITICAL);
            } elseif ($indexerStatus === 'working') {
                $status = HealthStatus::worse($status, HealthStatus::DEGRADED);
            }
            $backlog = $indexer['backlog'] ?? null;
            if (is_int($backlog) && $backlog > 100000) {
                $status = HealthStatus::worse($status, HealthStatus::DEGRADED);
            }
        }

        return $status;
    }

    private function normalize(string $status): string
    {
        $status = strtolower($status);

        return in_array($status, HealthStatus::ALL, true) ? $status : HealthStatus::HEALTHY;
    }

    private function collectorCodeFromError(string $error): ?string
    {
        $pos = strpos($error, ':');
        if ($pos === false) {
            return null;
        }

        $code = trim(substr($error, 0, $pos));

        return $code !== '' ? $code : null;
    }
}
