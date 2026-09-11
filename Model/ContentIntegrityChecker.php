<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Throwable;

/**
 * Scans DB-stored HTML (core_config_data head/footer + CMS) for Magecart-style
 * injected / obfuscated JavaScript. Behaviour-based — no live IOC hostnames.
 */
class ContentIntegrityChecker
{
    /**
     * @var list<string>
     */
    private const HTML_CONFIG_PATHS = [
        'design/head/includes',
        'design/head/demonotice',
        'design/footer/absolute_footer',
        'design/footer/copyright',
        'design/header/welcome',
    ];

    /**
     * @var list<string>
     */
    private const DEFAULT_SCRIPT_ALLOWLIST = [
        'googletagmanager.com',
        'google-analytics.com',
        'googletagservices.com',
        'google.com',
        'gstatic.com',
        'youtube.com',
        'recaptcha.net',
        'consentmanager.net',
        'cookiebot.com',
        'jquery.com',
        'jsdelivr.net',
        'cloudflare.com',
        'paypal.com',
        'paypalobjects.com',
        'klarna.com',
        'stripe.com',
        'trustedshops.com',
        'trustpilot.com',
        'addthis.com',
        'facebook.net',
        'hotjar.com',
        'clarity.ms',
    ];

    private const CMS_SCAN_LIMIT = 5000;

    private const MAX_FINDINGS_REPORTED = 50;

    private const CMS_CACHE_KEY = 'magewatch_cms_integrity';

    private const CMS_CACHE_TTL = 3600;

    private const CMS_INCREMENTAL_HOURS = 2;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Clock $clock,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{
     *     status: string,
     *     findings_total: int,
     *     findings: list<array<string, mixed>>,
     *     findings_truncated: bool,
     *     cms_scan_capped: bool,
     *     error?: string
     * }
     */
    public function scan(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $allowlist = $this->buildAllowlist($connection);

            $findings = [];
            $compromised = false;
            $truncated = false;

            foreach ($this->scanConfig($connection, $allowlist) as $finding) {
                $findings[] = $finding;
                $compromised = $compromised || $finding['severity'] === HealthStatus::COMPROMISED;
            }

            [$cmsFindings, $cmsCompromised, $cmsTruncated] = $this->scanCms($connection, $allowlist);
            $findings = array_merge($findings, $cmsFindings);
            $compromised = $compromised || $cmsCompromised;
            $truncated = $truncated || $cmsTruncated;

            $status = HealthStatus::HEALTHY;
            if ($compromised) {
                $status = HealthStatus::COMPROMISED;
            } elseif ($findings !== []) {
                $status = HealthStatus::DEGRADED;
            }

            return [
                'status' => $status,
                'findings_total' => count($findings),
                'findings' => array_slice($findings, 0, self::MAX_FINDINGS_REPORTED),
                'findings_truncated' => $truncated || count($findings) > self::MAX_FINDINGS_REPORTED,
                'cms_scan_capped' => $cmsTruncated,
            ];
        } catch (Throwable) {
            return [
                'status' => HealthStatus::CRITICAL,
                'findings_total' => 0,
                'findings' => [],
                'findings_truncated' => false,
                'cms_scan_capped' => false,
                'error' => 'Content integrity check failed',
            ];
        }
    }

    /**
     * @param  list<string>  $allowlist
     * @return list<array<string, mixed>>
     */
    private function scanConfig(AdapterInterface $connection, array $allowlist): array
    {
        $table = $this->resourceConnection->getTableName('core_config_data');
        $pathIn = $connection->quoteInto('path IN (?)', self::HTML_CONFIG_PATHS);
        $candidates = "({$pathIn}"
            ." OR value LIKE '%<script%'"
            ." OR value LIKE '%http-equiv%')";

        $select = $connection->select()
            ->from($table, ['config_id', 'scope', 'scope_id', 'path', 'value'])
            ->where('value IS NOT NULL')
            ->where("value != ''")
            ->where($candidates);

        $findings = [];
        foreach ($connection->fetchAll($select) as $row) {
            $hit = $this->scanValue((string) $row['value'], $allowlist);
            if ($hit === null) {
                continue;
            }
            $findings[] = $hit + [
                'source' => 'core_config_data',
                'config_id' => (int) $row['config_id'],
                'scope' => (string) $row['scope'],
                'scope_id' => (int) $row['scope_id'],
                'path' => (string) $row['path'],
            ];
        }

        return $findings;
    }

    /**
     * Full CMS walk at most once an hour. Every heartbeat also rescans rows
     * updated in the last two hours so a fresh Magecart drop is not delayed.
     *
     * @param  list<string>  $allowlist
     * @return array{0: list<array<string, mixed>>, 1: bool, 2: bool}
     */
    private function scanCms(AdapterInterface $connection, array $allowlist): array
    {
        $cached = $this->loadCachedCmsScan();
        if ($cached === null) {
            $fresh = $this->scanCmsTables($connection, $allowlist, null);
            $this->saveCachedCmsScan($fresh);

            return [$fresh['findings'], $fresh['compromised'], $fresh['truncated']];
        }

        $since = $this->clock->now()
            ->modify(sprintf('-%d hours', self::CMS_INCREMENTAL_HOURS))
            ->format('Y-m-d H:i:s');
        $incremental = $this->scanCmsTables($connection, $allowlist, $since);

        return $this->mergeCmsScans($cached, $incremental);
    }

    /**
     * @return array{findings: list<array<string, mixed>>, compromised: bool, truncated: bool}|null
     */
    private function loadCachedCmsScan(): ?array
    {
        $raw = $this->cache->load(self::CMS_CACHE_KEY);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['findings']) || ! is_array($decoded['findings'])) {
            return null;
        }

        return [
            'findings' => $decoded['findings'],
            'compromised' => (bool) ($decoded['compromised'] ?? false),
            'truncated' => (bool) ($decoded['truncated'] ?? false),
        ];
    }

    /**
     * @param  array{findings: list<array<string, mixed>>, compromised: bool, truncated: bool}  $scan
     */
    private function saveCachedCmsScan(array $scan): void
    {
        $this->cache->save(
            (string) json_encode($scan),
            self::CMS_CACHE_KEY,
            ['MAGEWATCH'],
            self::CMS_CACHE_TTL
        );
    }

    /**
     * @param  array{findings: list<array<string, mixed>>, compromised: bool, truncated: bool}  $cached
     * @param  array{findings: list<array<string, mixed>>, compromised: bool, truncated: bool}  $incremental
     * @return array{0: list<array<string, mixed>>, 1: bool, 2: bool}
     */
    private function mergeCmsScans(array $cached, array $incremental): array
    {
        $byKey = [];
        foreach (array_merge($cached['findings'], $incremental['findings']) as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $key = ($finding['source'] ?? '').':'.($finding['row_id'] ?? '').':'.($finding['identifier'] ?? '');
            $byKey[$key] = $finding;
        }

        return [
            array_values($byKey),
            $cached['compromised'] || $incremental['compromised'],
            $cached['truncated'] || $incremental['truncated'],
        ];
    }

    /**
     * @param  list<string>  $allowlist
     * @return array{findings: list<array<string, mixed>>, compromised: bool, truncated: bool}
     */
    private function scanCmsTables(AdapterInterface $connection, array $allowlist, ?string $updatedSince): array
    {
        $findings = [];
        $compromised = false;
        $truncated = false;

        $tables = [
            'cms_block' => ['id' => 'block_id', 'cols' => ['block_id', 'identifier', 'content']],
            'cms_page' => ['id' => 'page_id', 'cols' => ['page_id', 'identifier', 'content']],
        ];

        foreach ($tables as $logicalName => $meta) {
            $table = $this->resourceConnection->getTableName($logicalName);
            if (! $connection->isTableExists($table)) {
                continue;
            }

            if ($updatedSince === null) {
                $total = (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table}");
                if ($total > self::CMS_SCAN_LIMIT) {
                    $truncated = true;
                }
            }

            $select = $connection->select()
                ->from($table, $meta['cols'])
                ->where('content IS NOT NULL')
                ->where("content != ''")
                ->limit(self::CMS_SCAN_LIMIT);

            if ($updatedSince !== null) {
                $select->where('update_time >= ?', $updatedSince);
            }

            foreach ($connection->fetchAll($select) as $row) {
                $hit = $this->scanValue((string) $row['content'], $allowlist);
                if ($hit === null) {
                    continue;
                }
                $compromised = $compromised || $hit['severity'] === HealthStatus::COMPROMISED;
                $findings[] = $hit + [
                    'source' => $logicalName,
                    'row_id' => (int) $row[$meta['id']],
                    'identifier' => (string) $row['identifier'],
                ];
            }
        }

        return [
            'findings' => $findings,
            'compromised' => $compromised,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  list<string>  $allowlist
     * @return array{severity: string, signatures: list<string>, value_length: int, marker_offset: int}|null
     */
    private function scanValue(string $value, array $allowlist): ?array
    {
        $lower = strtolower($value);
        $compromise = [];
        $suspicious = [];

        if (str_contains($lower, 'fromcharcode')) {
            $compromise[] = 'String.fromCharCode char-code obfuscation';
        }
        if (str_contains($lower, 'import(_0x')) {
            $compromise[] = 'dynamic import of obfuscator variable';
        }
        if (str_contains($lower, "'68747470'") || str_contains($lower, '"68747470"')) {
            $compromise[] = 'hex-encoded URL array';
        }
        if (str_contains($lower, '_0x')
            && (str_contains($lower, 'eval(')
                || str_contains($lower, 'atob(')
                || str_contains($lower, 'unescape('))
        ) {
            $compromise[] = 'obfuscator variables with dynamic execution';
        }

        if (str_contains($lower, 'http-equiv="content-security-policy"')
            || str_contains($lower, "http-equiv='content-security-policy'")
        ) {
            $suspicious[] = 'inline Content-Security-Policy override';
        }
        foreach ($this->unrecognizedScriptHosts($value, $allowlist) as $host) {
            $suspicious[] = 'unrecognized external script host: '.$host;
        }

        if ($compromise !== []) {
            return [
                'severity' => HealthStatus::COMPROMISED,
                'signatures' => array_values(array_unique(array_merge($compromise, $suspicious))),
                'value_length' => strlen($value),
                'marker_offset' => $this->firstMarkerOffset($lower),
            ];
        }
        if ($suspicious !== []) {
            return [
                'severity' => HealthStatus::DEGRADED,
                'signatures' => array_values(array_unique($suspicious)),
                'value_length' => strlen($value),
                'marker_offset' => 0,
            ];
        }

        return null;
    }

    private function firstMarkerOffset(string $lower): int
    {
        foreach (['fromcharcode', 'import(_0x', '_0x', '68747470'] as $marker) {
            $pos = strpos($lower, $marker);
            if ($pos !== false) {
                return $pos;
            }
        }

        return 0;
    }

    /**
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    private function unrecognizedScriptHosts(string $value, array $allowlist): array
    {
        if (! preg_match_all('/<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $value, $matches)) {
            return [];
        }

        $unknown = [];
        foreach ($matches[1] as $src) {
            $host = strtolower((string) parse_url(trim($src), PHP_URL_HOST));
            if ($host === '') {
                continue;
            }
            if (! $this->hostAllowed($host, $allowlist)) {
                $unknown[$host] = true;
            }
        }

        return array_keys($unknown);
    }

    /**
     * @param  list<string>  $allowlist
     */
    private function hostAllowed(string $host, array $allowlist): bool
    {
        foreach ($allowlist as $allowed) {
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function buildAllowlist(AdapterInterface $connection): array
    {
        $allowlist = self::DEFAULT_SCRIPT_ALLOWLIST;
        $table = $this->resourceConnection->getTableName('core_config_data');
        $select = $connection->select()
            ->from($table, ['value'])
            ->where('path IN (?)', ['web/secure/base_url', 'web/unsecure/base_url'])
            ->where('value IS NOT NULL');

        foreach ($connection->fetchCol($select) as $baseUrl) {
            $host = strtolower((string) parse_url((string) $baseUrl, PHP_URL_HOST));
            if ($host !== '') {
                $allowlist[] = $host;
            }
        }

        return array_values(array_unique($allowlist));
    }
}
