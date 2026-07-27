<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\AdminSecurityChecker;
use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\HealthStatus;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class AdminSecurityCheckerTest extends TestCase
{
    public function testMissingTfaTableCountsAllActiveAdmins(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'is_active = 1') && ! str_contains($sql, 'failures_num')) {
                return 2;
            }
            if (str_contains($sql, 'failures_num')) {
                return 0;
            }
            if (str_contains($sql, 'lock_expires')) {
                return 0;
            }

            return 0;
        });
        $connection->method('isTableExists')->willReturn(false);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-27T12:00:00+00:00'));

        $result = (new AdminSecurityChecker($resource, $clock))->scan();

        $this->assertSame(2, $result['users_without_2fa']);
        $this->assertFalse($result['tfa_available']);
        $this->assertSame(HealthStatus::DEGRADED, $result['status']);
    }
}
