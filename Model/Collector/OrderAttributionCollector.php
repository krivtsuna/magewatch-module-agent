<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\Attribution\OrderAttributionRecorder;
use MageWatch\Agent\Model\Clock;
use MageWatch\Agent\Model\Config;

/**
 * Daily order counts and base-currency revenue grouped by campaign.
 *
 * Sends aggregates only — no increment IDs, emails, or ad click IDs. Raw
 * per-order rows stay in Magento. Last-touch is the default reporting model;
 * first-touch ships alongside so SaaS can switch later without a module bump.
 */
class OrderAttributionCollector implements CollectorInterface
{
    public const CODE = 'order_attribution';

    private const WINDOW_DAYS = 7;

    private const TOP_PER_DAY = 50;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Clock $clock,
        private readonly Config $config
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $ordersTable = $this->resourceConnection->getTableName('sales_order');
        $attrTable = $this->resourceConnection->getTableName(OrderAttributionRecorder::TABLE);

        if (! $connection->isTableExists($ordersTable) || ! $connection->isTableExists($attrTable)) {
            return $this->payload(0, 0, [], []);
        }

        $since = $this->clock->now()
            ->setTime(0, 0, 0)
            ->modify(sprintf('-%d days', self::WINDOW_DAYS - 1))
            ->format('Y-m-d H:i:s');

        $coverage = $this->coverage($connection, $ordersTable, $attrTable, $since);

        return $this->payload(
            $coverage['orders'],
            $coverage['attributed'],
            $this->grouped($connection, $ordersTable, $attrTable, $since, 'last'),
            $this->grouped($connection, $ordersTable, $attrTable, $since, 'first')
        );
    }

    /**
     * @return array{orders: int, attributed: int}
     */
    private function coverage(AdapterInterface $connection, string $ordersTable, string $attrTable, string $since): array
    {
        $select = $connection->select()
            ->from(['o' => $ordersTable], [
                'orders' => new Expression('COUNT(*)'),
                'attributed' => new Expression('COUNT(a.order_id)'),
            ])
            ->joinLeft(['a' => $attrTable], 'a.order_id = o.entity_id', [])
            ->where('o.created_at >= ?', $since);

        $row = $connection->fetchRow($select) ?: [];

        return [
            'orders' => (int) ($row['orders'] ?? 0),
            'attributed' => (int) ($row['attributed'] ?? 0),
        ];
    }

    /**
     * @return list<array{day: string, source: string, medium: string, campaign: string, count: int, revenue: float}>
     */
    private function grouped(
        AdapterInterface $connection,
        string $ordersTable,
        string $attrTable,
        string $since,
        string $touch
    ): array {
        $source = $touch.'_source';
        $medium = $touch.'_medium';
        $campaign = $touch.'_campaign';

        $select = $connection->select()
            ->from(['a' => $attrTable], [
                'day' => new Expression('DATE(o.created_at)'),
                'source' => $source,
                'medium' => $medium,
                'campaign' => $campaign,
                'cnt' => new Expression('COUNT(*)'),
                'revenue' => new Expression('SUM(o.base_grand_total)'),
            ])
            ->joinInner(['o' => $ordersTable], 'o.entity_id = a.order_id', [])
            ->where('o.created_at >= ?', $since)
            ->group(['day', $source, $medium, $campaign]);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'day' => (string) $row['day'],
                'source' => (string) $row['source'],
                'medium' => (string) $row['medium'],
                'campaign' => (string) $row['campaign'],
                'count' => (int) $row['cnt'],
                'revenue' => round((float) $row['revenue'], 2),
            ];
        }

        return $this->capDaily($rows);
    }

    /**
     * @param  list<array{day: string, source: string, medium: string, campaign: string, count: int, revenue: float}>  $rows
     * @return list<array{day: string, source: string, medium: string, campaign: string, count: int, revenue: float}>
     */
    private function capDaily(array $rows): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['day']][] = $row;
        }

        ksort($byDay);

        $out = [];
        foreach ($byDay as $day => $dayRows) {
            usort(
                $dayRows,
                static function (array $left, array $right): int {
                    return $right['count'] <=> $left['count']
                        ?: $right['revenue'] <=> $left['revenue']
                        ?: $left['source'] <=> $right['source'];
                }
            );

            $kept = array_slice($dayRows, 0, self::TOP_PER_DAY);
            $rest = array_slice($dayRows, self::TOP_PER_DAY);
            if ($rest !== []) {
                $kept[] = [
                    'day' => $day,
                    'source' => '(other)',
                    'medium' => '(other)',
                    'campaign' => '(other)',
                    'count' => (int) array_sum(array_column($rest, 'count')),
                    'revenue' => round((float) array_sum(array_column($rest, 'revenue')), 2),
                ];
            }

            foreach ($kept as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{day: string, source: string, medium: string, campaign: string, count: int, revenue: float}>  $lastTouch
     * @param  list<array{day: string, source: string, medium: string, campaign: string, count: int, revenue: float}>  $firstTouch
     * @return array<string, mixed>
     */
    private function payload(int $orders, int $attributed, array $lastTouch, array $firstTouch): array
    {
        return [
            'order_attribution' => [
                'window_days' => self::WINDOW_DAYS,
                'attribution_window_days' => $this->config->getAttributionWindowDays(),
                'coverage' => [
                    'orders' => $orders,
                    'attributed' => $attributed,
                ],
                'last_touch' => $lastTouch,
                'first_touch' => $firstTouch,
            ],
        ];
    }
}
