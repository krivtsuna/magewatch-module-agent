<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\ContentIntegrityChecker;
use MageWatch\Agent\Model\HealthStatus;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
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

        $checker = $this->checker($resource);
        $method = new ReflectionMethod(ContentIntegrityChecker::class, 'scanValue');
        $method->setAccessible(true);

        /** @var array{severity: string, signatures: list<string>, value_length: int, marker_offset: int}|null $hit */
        $hit = $method->invoke($checker, $value, [
            'googletagmanager.com',
            'google-analytics.com',
        ]);

        return $hit;
    }

    public function test_scan_reuses_cached_cms_and_merges_recent_updates(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('quoteInto')->willReturn('path IN (\'x\')');
        $connection->method('fetchCol')->willReturn([]);
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [],
            [
                [
                    'block_id' => 9,
                    'identifier' => 'fresh-drop',
                    'content' => 'legit html String.fromCharCode(104,116,116,112)',
                ],
            ],
            [],
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(json_encode([
            'findings' => [
                [
                    'severity' => HealthStatus::DEGRADED,
                    'signatures' => ['unrecognized external script host: old.example'],
                    'source' => 'cms_block',
                    'row_id' => 1,
                    'identifier' => 'old',
                ],
            ],
            'compromised' => false,
            'truncated' => true,
        ]));

        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-09-11 12:00:00'));

        $result = (new ContentIntegrityChecker($resource, $clock, $cache))->scan();

        $this->assertSame(HealthStatus::COMPROMISED, $result['status']);
        $this->assertTrue($result['cms_scan_capped']);
        $this->assertSame(2, $result['findings_total']);
    }

    private function checker(ResourceConnection $resource): ContentIntegrityChecker
    {
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-09-11 12:00:00'));
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        return new ContentIntegrityChecker($resource, $clock, $cache);
    }
}
