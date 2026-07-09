<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\Clock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Reports order count and base-currency revenue per hour for the last 7
 * days via a single aggregated query. No anomaly detection is done here -
 * the SaaS side interprets the raw series and persists hourly buckets.
 */
class OrderStatsCollector implements CollectorInterface
{
    private const CODE = 'order_stats';

    private const HOURS = 168;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Clock $clock
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');

        $now = $this->clock->now();
        $currentHour = $now->setTime((int) $now->format('H'), 0, 0);
        $windowStart = $currentHour->modify(sprintf('-%d hours', self::HOURS - 1));

        $buckets = [];
        for ($i = 0; $i < self::HOURS; $i++) {
            $hour = $windowStart->modify(sprintf('+%d hours', $i));
            $key = $hour->format('Y-m-d H:00:00');
            $buckets[$key] = [
                'hour' => $hour->format(DATE_ATOM),
                'count' => 0,
                'revenue' => 0.0,
                'currency' => 'base',
            ];
        }

        $hourExpr = new Expression("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')");
        $select = $connection->select()
            ->from($table, [
                'hour_bucket' => $hourExpr,
                'cnt' => new Expression('COUNT(*)'),
                'revenue' => new Expression('SUM(base_grand_total)'),
            ])
            ->where('created_at >= ?', $windowStart->format('Y-m-d H:i:s'))
            ->group('hour_bucket');

        foreach ($connection->fetchAll($select) as $row) {
            $key = (string) $row['hour_bucket'];
            if (isset($buckets[$key])) {
                $buckets[$key]['count'] = (int) $row['cnt'];
                $buckets[$key]['revenue'] = round((float) $row['revenue'], 2);
            }
        }

        $stuckHours = 2;
        $stuckSince = $now->modify(sprintf('-%d hours', $stuckHours))->format('Y-m-d H:i:s');
        $stuckSelect = $connection->select()
            ->from($table, ['cnt' => new Expression('COUNT(*)')])
            ->where('status = ?', 'pending_payment')
            ->where('created_at < ?', $stuckSince);
        $pendingPaymentStuck = (int) $connection->fetchOne($stuckSelect);

        return [
            'orders_hourly' => array_values($buckets),
            'orders' => [
                'pending_payment_stuck' => $pendingPaymentStuck,
            ],
        ];
    }
}
