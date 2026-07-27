<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\ContentIntegrityChecker;
use MageWatch\Agent\Model\HealthStatus;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ContentIntegrityCheckerTest extends TestCase
{
    public function testDetectsFromCharCodeAsCompromised(): void
    {
        $hit = $this->scanValue('legit html String.fromCharCode(104,116,116,112)');

        $this->assertNotNull($hit);
        $this->assertSame(HealthStatus::COMPROMISED, $hit['severity']);
        $this->assertNotSame(0, $hit['marker_offset']);
    }

    public function testDetectsUnrecognizedExternalScriptAsDegraded(): void
    {
        $hit = $this->scanValue('<script src="https://evil-cdn.example/x.js"></script>');

        $this->assertNotNull($hit);
        $this->assertSame(HealthStatus::DEGRADED, $hit['severity']);
        $matched = false;
        foreach ($hit['signatures'] as $signature) {
            if (str_contains($signature, 'evil-cdn.example')) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue($matched);
    }

    public function testAllowlistedScriptIsClean(): void
    {
        $hit = $this->scanValue('<script src="https://www.googletagmanager.com/gtm.js"></script>');

        $this->assertNull($hit);
    }

    /**
     * @return array{severity: string, signatures: list<string>, value_length: int, marker_offset: int}|null
     */
    private function scanValue(string $value): ?array
    {
        $connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $checker = new ContentIntegrityChecker($resource);
        $method = new ReflectionMethod(ContentIntegrityChecker::class, 'scanValue');
        $method->setAccessible(true);

        /** @var array{severity: string, signatures: list<string>, value_length: int, marker_offset: int}|null $hit */
        $hit = $method->invoke($checker, $value, [
            'googletagmanager.com',
            'google-analytics.com',
        ]);

        return $hit;
    }
}
