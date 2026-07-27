<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
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
     *     tfa_available: bool,
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

            $tfa = $this->countUsersWithout2fa($connection, $adminUserTable, $totalAdmins);

            $status = HealthStatus::HEALTHY;
            if ($failedLogins > self::FAILED_LOGIN_CRITICAL || $lockedAccounts > 3) {
                $status = HealthStatus::CRITICAL;
            } elseif ($failedLogins > self::FAILED_LOGIN_WARNING || $tfa['users_without_2fa'] > 0) {
                $status = HealthStatus::DEGRADED;
            }

            return [
                'status' => $status,
                'active_admins' => $totalAdmins,
                'failed_logins_24h' => $failedLogins,
                'locked_accounts' => $lockedAccounts,
                'active_sessions' => $activeSessions,
                'users_without_2fa' => $tfa['users_without_2fa'],
                'tfa_available' => $tfa['tfa_available'],
            ];
        } catch (Throwable) {
            return [
                'status' => HealthStatus::CRITICAL,
                'active_admins' => 0,
                'failed_logins_24h' => 0,
                'locked_accounts' => 0,
                'active_sessions' => 0,
                'users_without_2fa' => 0,
                'tfa_available' => false,
                'error' => 'Admin security check failed',
            ];
        }
    }

    /**
     * When Magento_TwoFactorAuth is disabled the enrollment table is often
     * missing — that must NOT look like "everyone has 2FA".
     *
     * @return array{users_without_2fa: int, tfa_available: bool}
     */
    private function countUsersWithout2fa(
        AdapterInterface $connection,
        string $adminUserTable,
        int $totalAdmins,
    ): array {
        $tfaTable = $this->resolveTfaTable($connection);
        if ($tfaTable === null) {
            return [
                'users_without_2fa' => $totalAdmins,
                'tfa_available' => false,
            ];
        }

        $hasEncodedConfig = $this->columnExists($connection, $tfaTable, 'encoded_config');

        if ($hasEncodedConfig) {
            $count = (int) $connection->fetchOne(
                sprintf(
                    'SELECT COUNT(*) FROM %s u'
                    .' WHERE u.is_active = 1'
                    .' AND ('
                    .'   NOT EXISTS (SELECT 1 FROM %s t WHERE t.user_id = u.user_id)'
                    .'   OR EXISTS ('
                    .'     SELECT 1 FROM %s t'
                    .'     WHERE t.user_id = u.user_id'
                    .'       AND (t.encoded_config IS NULL OR t.encoded_config = \'\')'
                    .'   )'
                    .' )',
                    $adminUserTable,
                    $tfaTable,
                    $tfaTable
                )
            );
        } else {
            $count = (int) $connection->fetchOne(
                sprintf(
                    'SELECT COUNT(*) FROM %s u'
                    .' WHERE u.is_active = 1'
                    .' AND NOT EXISTS (SELECT 1 FROM %s t WHERE t.user_id = u.user_id)',
                    $adminUserTable,
                    $tfaTable
                )
            );
        }

        return [
            'users_without_2fa' => $count,
            'tfa_available' => true,
        ];
    }

    private function resolveTfaTable(AdapterInterface $connection): ?string
    {
        foreach (['tfa_user_config', 'msp_tfa_user_config'] as $logical) {
            $table = $this->resourceConnection->getTableName($logical);
            if ($connection->isTableExists($table)) {
                return $table;
            }
        }

        return null;
    }

    private function columnExists(AdapterInterface $connection, string $table, string $column): bool
    {
        try {
            $describe = $connection->describeTable($table);

            return isset($describe[$column]);
        } catch (Throwable) {
            return false;
        }
    }
}
