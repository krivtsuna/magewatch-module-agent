<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use Magento\Framework\App\ResourceConnection;
use Throwable;

/**
 * Lightweight admin account hygiene: failed logins, locks, missing 2FA.
 */
class AdminSecurityChecker
{
    private const FAILED_LOGIN_WARNING = 20;

    private const FAILED_LOGIN_CRITICAL = 100;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     status: string,
     *     active_admins: int,
     *     failed_logins_24h: int,
     *     locked_accounts: int,
     *     active_sessions: int,
     *     users_without_2fa: int,
     *     error?: string
     * }
     */
    public function scan(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $adminUserTable = $this->resourceConnection->getTableName('admin_user');
            $since = $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s');

            $totalAdmins = (int) $connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE is_active = 1', $adminUserTable)
            );

            $failedLogins = (int) $connection->fetchOne(
                sprintf(
                    'SELECT COALESCE(SUM(failures_num), 0) FROM %s'
                    .' WHERE first_failure >= :since AND failures_num > 0',
                    $adminUserTable
                ),
                ['since' => $since]
            );

            $lockedAccounts = (int) $connection->fetchOne(
                sprintf(
                    'SELECT COUNT(*) FROM %s WHERE lock_expires IS NOT NULL AND lock_expires > UTC_TIMESTAMP()',
                    $adminUserTable
                )
            );

            $activeSessions = 0;
            $sessionTable = $this->resourceConnection->getTableName('admin_user_session');
            if ($connection->isTableExists($sessionTable)) {
                $activeSessions = (int) $connection->fetchOne(
                    sprintf('SELECT COUNT(*) FROM %s WHERE status = 1', $sessionTable)
                );
            }

            $usersWithout2fa = 0;
            $tfaTable = $this->resourceConnection->getTableName('tfa_user_config');
            if ($connection->isTableExists($tfaTable)) {
                $usersWithout2fa = (int) $connection->fetchOne(
                    sprintf(
                        'SELECT COUNT(*) FROM %s u'
                        .' WHERE u.is_active = 1'
                        .' AND u.user_id NOT IN (SELECT DISTINCT user_id FROM %s)',
                        $adminUserTable,
                        $tfaTable
                    )
                );
            }

            $status = HealthStatus::HEALTHY;
            if ($failedLogins > self::FAILED_LOGIN_CRITICAL || $lockedAccounts > 3) {
                $status = HealthStatus::CRITICAL;
            } elseif ($failedLogins > self::FAILED_LOGIN_WARNING || $usersWithout2fa > 0) {
                $status = HealthStatus::DEGRADED;
            }

            return [
                'status' => $status,
                'active_admins' => $totalAdmins,
                'failed_logins_24h' => $failedLogins,
                'locked_accounts' => $lockedAccounts,
                'active_sessions' => $activeSessions,
                'users_without_2fa' => $usersWithout2fa,
            ];
        } catch (Throwable) {
            return [
                'status' => HealthStatus::CRITICAL,
                'active_admins' => 0,
                'failed_logins_24h' => 0,
                'locked_accounts' => 0,
                'active_sessions' => 0,
                'users_without_2fa' => 0,
                'error' => 'Admin security check failed',
            ];
        }
    }
}
