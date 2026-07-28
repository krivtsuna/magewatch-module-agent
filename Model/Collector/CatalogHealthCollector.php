<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\Clock;

/**
 * Aggregate catalog buyability signals for SaaS revenue-leak detection.
 * Returns counts and SKU lists only — no product names, URLs, or customer data.
 */
class CatalogHealthCollector implements CollectorInterface
{
    private const CODE = 'catalog_health';

    private const BESTSELLER_LIMIT = 20;

    private const BESTSELLER_DAYS = 30;

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
        if (! $connection->isTableExists($this->table('catalog_product_entity'))) {
            return [
                'catalog_health' => [
                    'missing_price' => 0,
                    'missing_image' => 0,
                    'configurables_without_options' => 0,
                    'bestsellers_oos' => [],
                ],
            ];
        }

        return [
            'catalog_health' => [
                'missing_price' => $this->countMissingPrice($connection),
                'missing_image' => $this->countMissingImage($connection),
                'configurables_without_options' => $this->countConfigurablesWithoutOptions($connection),
                'bestsellers_oos' => $this->bestsellersOutOfStock($connection),
            ],
        ];
    }

    private function countMissingPrice(AdapterInterface $connection): int
    {
        $priceAttrId = $this->attributeId($connection, 'catalog_product', 'price');
        if ($priceAttrId === null) {
            return 0;
        }

        $product = $this->table('catalog_product_entity');
        $decimal = $this->table('catalog_product_entity_decimal');
        $select = $connection->select()
            ->from(['p' => $product], ['cnt' => new Expression('COUNT(*)')])
            ->joinLeft(
                ['price' => $decimal],
                'price.entity_id = p.entity_id AND price.attribute_id = '.(int) $priceAttrId
                    .' AND price.store_id = 0',
                []
            )
            ->where('p.type_id IN (?)', ['simple', 'virtual', 'downloadable'])
            ->where('(price.value IS NULL OR price.value <= 0)');

        return (int) $connection->fetchOne($select);
    }

    private function countMissingImage(AdapterInterface $connection): int
    {
        $product = $this->table('catalog_product_entity');
        $mediaLink = $this->table('catalog_product_entity_media_gallery_value_to_entity');
        if (! $connection->isTableExists($mediaLink)) {
            return 0;
        }

        $select = $connection->select()
            ->from(['p' => $product], ['cnt' => new Expression('COUNT(*)')])
            ->joinLeft(
                ['m' => $mediaLink],
                'm.entity_id = p.entity_id',
                []
            )
            ->where('p.type_id IN (?)', ['simple', 'configurable', 'virtual', 'downloadable', 'bundle', 'grouped'])
            ->where('m.value_id IS NULL');

        return (int) $connection->fetchOne($select);
    }

    private function countConfigurablesWithoutOptions(AdapterInterface $connection): int
    {
        $product = $this->table('catalog_product_entity');
        $link = $this->table('catalog_product_super_link');
        if (! $connection->isTableExists($link)) {
            return 0;
        }

        $select = $connection->select()
            ->from(['p' => $product], ['cnt' => new Expression('COUNT(*)')])
            ->joinLeft(
                ['l' => $link],
                'l.parent_id = p.entity_id',
                []
            )
            ->where('p.type_id = ?', 'configurable')
            ->where('l.product_id IS NULL');

        return (int) $connection->fetchOne($select);
    }

    /**
     * @return list<array{sku: string, qty_ordered: float}>
     */
    private function bestsellersOutOfStock(AdapterInterface $connection): array
    {
        $orderItem = $this->table('sales_order_item');
        if (! $connection->isTableExists($orderItem)) {
            return [];
        }

        $since = $this->clock->now()
            ->modify(sprintf('-%d days', self::BESTSELLER_DAYS))
            ->format('Y-m-d H:i:s');

        $select = $connection->select()
            ->from($orderItem, [
                'sku' => 'sku',
                'qty_ordered' => new Expression('SUM(qty_ordered)'),
            ])
            ->where('created_at >= ?', $since)
            ->where('parent_item_id IS NULL')
            ->where('sku IS NOT NULL')
            ->where('sku != ?', '')
            ->group('sku')
            ->order('qty_ordered DESC')
            ->limit(self::BESTSELLER_LIMIT);

        $top = $connection->fetchAll($select);
        if ($top === []) {
            return [];
        }

        $skus = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['sku'] ?? ''),
            $top
        )));
        if ($skus === []) {
            return [];
        }

        $oosSkus = $this->outOfStockSkus($connection, $skus);
        if ($oosSkus === []) {
            return [];
        }

        $result = [];
        foreach ($top as $row) {
            $sku = (string) ($row['sku'] ?? '');
            if ($sku === '' || ! isset($oosSkus[$sku])) {
                continue;
            }
            $result[] = [
                'sku' => $sku,
                'qty_ordered' => round((float) ($row['qty_ordered'] ?? 0), 2),
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $skus
     * @return array<string, true>
     */
    private function outOfStockSkus(AdapterInterface $connection, array $skus): array
    {
        $product = $this->table('catalog_product_entity');
        $stockItem = $this->table('cataloginventory_stock_item');
        if (! $connection->isTableExists($stockItem)) {
            return [];
        }

        $select = $connection->select()
            ->from(['p' => $product], ['sku'])
            ->join(
                ['s' => $stockItem],
                's.product_id = p.entity_id',
                []
            )
            ->where('p.sku IN (?)', $skus)
            ->where('(s.is_in_stock = 0 OR s.qty <= 0)');

        $oos = [];
        foreach ($connection->fetchCol($select) as $sku) {
            $oos[(string) $sku] = true;
        }

        return $oos;
    }

    private function attributeId(AdapterInterface $connection, string $entityTypeCode, string $code): ?int
    {
        $eavAttribute = $this->table('eav_attribute');
        $eavEntityType = $this->table('eav_entity_type');
        if (! $connection->isTableExists($eavAttribute) || ! $connection->isTableExists($eavEntityType)) {
            return null;
        }

        $select = $connection->select()
            ->from(['a' => $eavAttribute], ['attribute_id'])
            ->join(
                ['t' => $eavEntityType],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->where('t.entity_type_code = ?', $entityTypeCode)
            ->where('a.attribute_code = ?', $code)
            ->limit(1);

        $id = $connection->fetchOne($select);

        return $id !== false && $id !== null ? (int) $id : null;
    }

    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }
}
