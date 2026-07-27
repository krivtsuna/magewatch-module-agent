<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Production config hygiene: debug risks + common performance flags.
 */
class ConfigHygieneChecker
{
    /**
     * @var array<string, string>
     */
    private const MUST_BE_OFF = [
        'dev/debug/template_hints_storefront' => 'Storefront template hints enabled',
        'dev/debug/template_hints_admin' => 'Admin template hints enabled',
        'dev/template/allow_symlink' => 'Template symlinks allowed',
    ];

    /**
     * @var array<string, string>
     */
    private const SHOULD_BE_ON = [
        'dev/css/minify_files' => 'CSS minification disabled',
        'dev/js/minify_files' => 'JS minification disabled',
        'dev/static/sign' => 'Static content signing disabled',
        'web/seo/use_rewrites' => 'URL rewrites disabled',
    ];

    /**
     * @var array<string, string>
     */
    private const ADVISORY_ON = [
        'dev/css/merge_css_files' => 'CSS merge disabled',
        'dev/js/merge_files' => 'JS merge disabled',
        'sales_email/general/async_sending' => 'Async email sending disabled',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * @return array{
     *     status: string,
     *     critical_issues: list<string>,
     *     warnings: list<string>,
     *     advisory: list<string>,
     *     checks_passed: int,
     *     checks_total: int
     * }
     */
    public function scan(): array
    {
        $critical = [];
        $warnings = [];
        $advisory = [];

        foreach (self::MUST_BE_OFF as $path => $label) {
            if ($this->scopeConfig->isSetFlag($path)) {
                $critical[] = $label;
            }
        }

        foreach (self::SHOULD_BE_ON as $path => $label) {
            if (! $this->scopeConfig->isSetFlag($path)) {
                $warnings[] = $label;
            }
        }

        foreach (self::ADVISORY_ON as $path => $label) {
            if (! $this->scopeConfig->isSetFlag($path)) {
                $advisory[] = $label;
            }
        }

        $totalChecks = count(self::MUST_BE_OFF) + count(self::SHOULD_BE_ON) + count(self::ADVISORY_ON);
        $issueCount = count($critical) + count($warnings);

        $status = HealthStatus::HEALTHY;
        if ($critical !== []) {
            $status = HealthStatus::CRITICAL;
        } elseif ($warnings !== []) {
            $status = HealthStatus::DEGRADED;
        }

        return [
            'status' => $status,
            'critical_issues' => $critical,
            'warnings' => $warnings,
            'advisory' => $advisory,
            'checks_passed' => $totalChecks - $issueCount - count($advisory),
            'checks_total' => $totalChecks,
        ];
    }
}
