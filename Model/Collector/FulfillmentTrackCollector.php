<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\Clock;

/**
 * Open Magento shipment tracks for SaaS to resolve the underlying carrier
 * and poll its API/page. Destination region/city/country/postcode only —
 * no customer name, email, phone, or street.
 */
class FulfillmentTrackCollector implements CollectorInterface
{
    public const CODE = 'fulfillment';

    private const WINDOW_DAYS = 90;

    private const MAX_TRACKS = 200;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Clock $clock
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orders = $this->resourceConnection->getTableName('sales_order');
        $shipments = $this->resourceConnection->getTableName('sales_shipment');
        $tracks = $this->resourceConnection->getTableName('sales_shipment_track');
        $addresses = $this->resourceConnection->getTableName('sales_order_address');

        if (! $connection->isTableExists($orders)
            || ! $connection->isTableExists($shipments)
            || ! $connection->isTableExists($tracks)
        ) {
            return $this->payload([]);
        }

        $since = $this->clock->now()
            ->modify(sprintf('-%d days', self::WINDOW_DAYS))
            ->format('Y-m-d H:i:s');

        return $this->payload($this->rows(
            $connection,
            $orders,
            $shipments,
            $tracks,
            $since,
            $connection->isTableExists($addresses) ? $addresses : null,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(
        AdapterInterface $connection,
        string $orders,
        string $shipments,
        string $tracks,
        string $since,
        ?string $addresses
    ): array {
        $select = $connection->select()
            ->from(['t' => $tracks], [
                'track_number' => 't.track_number',
                'carrier_code' => 't.carrier_code',
                'carrier_title' => 't.title',
                'track_created_at' => 't.created_at',
            ])
            ->join(['s' => $shipments], 's.entity_id = t.parent_id', [
                'shipped_at' => 's.created_at',
            ])
            ->join(['o' => $orders], 'o.entity_id = s.order_id', [
                'increment_id' => 'o.increment_id',
                'order_status' => 'o.status',
                'order_total' => 'o.base_grand_total',
                'currency' => 'o.base_currency_code',
            ]);

        if ($addresses !== null) {
            $select->joinLeft(
                ['a' => $addresses],
                "a.parent_id = o.entity_id AND a.address_type = 'shipping'",
                [
                    'ship_country' => 'a.country_id',
                    'ship_region' => 'a.region',
                    'ship_city' => 'a.city',
                    'ship_postcode' => 'a.postcode',
                ]
            );
        }

        $select
            ->where('t.track_number IS NOT NULL')
            ->where('t.track_number != ?', '')
            ->where('s.created_at >= ?', $since)
            ->where('o.state IN (?)', ['processing', 'complete'])
            ->order('s.created_at DESC')
            ->limit(self::MAX_TRACKS);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $number = trim((string) ($row['track_number'] ?? ''));
            if ($number === '') {
                continue;
            }

            $out[] = [
                'increment_id' => (string) $row['increment_id'],
                'track_number' => $number,
                'carrier_code' => (string) ($row['carrier_code'] ?? ''),
                'carrier_title' => (string) ($row['carrier_title'] ?? ''),
                'order_status' => (string) ($row['order_status'] ?? ''),
                'order_total' => round((float) ($row['order_total'] ?? 0), 2),
                'currency' => (string) ($row['currency'] ?? ''),
                'shipped_at' => $this->atom((string) ($row['shipped_at'] ?? '')),
                'track_created_at' => $this->atom((string) ($row['track_created_at'] ?? '')),
                'ship_country' => (string) ($row['ship_country'] ?? ''),
                'ship_region' => (string) ($row['ship_region'] ?? ''),
                'ship_city' => (string) ($row['ship_city'] ?? ''),
                'ship_postcode' => (string) ($row['ship_postcode'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $tracks
     * @return array<string, mixed>
     */
    private function payload(array $tracks): array
    {
        return [
            'fulfillment' => [
                'window_days' => self::WINDOW_DAYS,
                'tracks' => $tracks,
            ],
        ];
    }

    private function atom(string $mysql): ?string
    {
        if ($mysql === '') {
            return null;
        }

        $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysql);
        if ($at === false) {
            return null;
        }

        return $at->format(DATE_ATOM);
    }
}
