<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\ConfigHygieneChecker;
use MageWatch\Agent\Model\HealthStatus;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigHygieneCheckerTest extends TestCase
{
    public function testTemplateHintsAreCritical(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(
            fn (string $path) => $path === 'dev/debug/template_hints_storefront'
        );

        $result = (new ConfigHygieneChecker($scope))->scan();

        $this->assertSame(HealthStatus::CRITICAL, $result['status']);
        $this->assertContains('Storefront template hints enabled', $result['critical_issues']);
    }

    public function testMissingMinifyIsDegraded(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(false);

        $result = (new ConfigHygieneChecker($scope))->scan();

        $this->assertSame(HealthStatus::DEGRADED, $result['status']);
        $this->assertSame([], $result['critical_issues']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testCleanConfigIsHealthy(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(
            fn (string $path) => ! in_array($path, [
                'dev/debug/template_hints_storefront',
                'dev/debug/template_hints_admin',
                'dev/template/allow_symlink',
            ], true)
        );

        $result = (new ConfigHygieneChecker($scope))->scan();

        $this->assertSame(HealthStatus::HEALTHY, $result['status']);
        $this->assertSame([], $result['critical_issues']);
        $this->assertSame([], $result['warnings']);
    }
}
