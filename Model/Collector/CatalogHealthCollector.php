<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\Clock;

/**
 * Aggregate catalog buyability signals for SaaS revenue-leak detection.
 * Returns counts and SKU lists only — no product names, URLs, or customer data.
 *
 * Image/price counts are limited to enabled + catalog-visible products so
 * disabled SKUs and "Not Visible Individually" children do not inflate leaks.
 */
class CatalogHealthCollector implements CollectorInterface
{
    private const CODE = 'catalog_health';

    private const BESTSELLER_LIMIT = 20;

    private const BESTSELLER_DAYS = 30;

    private const SAMPLE_LIMIT = 10;

    /** Catalog / Search / Both — excludes Not Visible Individually. */
    private const VISIBLE_VALUES = [2, 3, 4];

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
                    'missing_price_skus' => [],
                    'missing_image' => 0,
                    'missing_image_skus' => [],
                    'configurables_without_options' => 0,
                    'configurables_without_options_skus' => [],
                    'bestsellers_oos' => [],
                ],
            ];
        }

        $missingPrice = $this->findMissingPrice($connection);
        $missingImage = $this->findMissingImage($connection);
        $configurables = $this->findConfigurablesWithoutOptions($connection);

        return [
            'catalog_health' => [
                'missing_price' => count($missingPrice),
                'missing_price_skus' => array_slice($missingPrice, 0, self::SAMPLE_LIMIT),
                'missing_image' => count($missingImage),
                'missing_image_skus' => array_slice($missingImage, 0, self::SAMPLE_LIMIT),
                'configurables_without_options' => count($configurables),
                'configurables_without_options_skus' => array_slice($configurables, 0, self::SAMPLE_LIMIT),
                'bestsellers_oos' => $this->bestsellersOutOfStock($connection),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function findMissingPrice(AdapterInterface $connection): array
    {
        $priceAttrId = $this->attributeId($connection, 'catalog_product', 'price');
        if ($priceAttrId === null) {
            return [];
        }

        $product = $this->table('catalog_product_entity');
        $decimal = $this->table('catalog_product_entity_decimal');
        $select = $connection->select()
            ->from(['p' => $product], ['sku'])
            ->joinLeft(
                ['price' => $decimal],
                'price.entity_id = p.entity_id AND price.attribute_id = '.(int) $priceAttrId
                    .' AND price.store_id = 0',
                []
            )
            ->where('p.type_id IN (?)', ['simple', 'virtual', 'downloadable'])
            ->where('(price.value IS NULL OR price.value <= 0)')
            ->order('p.sku ASC')
            ->limit(500);

        $this->joinEnabled($connection, $select);
        $this->joinVisible($connection, $select);

        return $this->fetchSkus($connection, $select);
    }

    /**
     * @return list<string>
     */
    private function findMissingImage(AdapterInterface $connection): array
    {
        $imageAttrId = $this->attributeId($connection, 'catalog_product', 'image');
        if ($imageAttrId === null) {
            return [];
        }

        $product = $this->table('catalog_product_entity');
        $varchar = $this->table('catalog_product_entity_varchar');
        $select = $connection->select()
            ->from(['p' => $product], ['sku'])
            ->joinLeft(
                ['img' => $varchar],
                'img.entity_id = p.entity_id AND img.attribute_id = '.(int) $imageAttrId
                    .' AND img.store_id = 0',
                []
            )
            ->where('p.type_id IN (?)', ['simple', 'configurable', 'virtual', 'downloadable', 'bundle', 'grouped'])
            ->where('(img.value IS NULL OR img.value = \'\' OR img.value = \'no_selection\')')
            ->order('p.sku ASC')
            ->limit(500);

        $this->joinEnabled($connection, $select);
        $this->joinVisible($connection, $select);

        return $this->fetchSkus($connection, $select);
    }

    /**
     * @return list<string>
     */
    private function findConfigurablesWithoutOptions(AdapterInterface $connection): array
    {
        $product = $this->table('catalog_product_entity');
        $link = $this->table('catalog_product_super_link');
        if (! $connection->isTableExists($link)) {
            return [];
        }

        $select = $connection->select()
            ->from(['p' => $product], ['sku'])
            ->joinLeft(
                ['l' => $link],
                'l.parent_id = p.entity_id',
                []
            )
            ->where('p.type_id = ?', 'configurable')
            ->where('l.product_id IS NULL')
            ->order('p.sku ASC')
            ->limit(500);

        $this->joinEnabled($connection, $select);

        return $this->fetchSkus($connection, $select);
    }

    private function joinEnabled(AdapterInterface $connection, Select $select): void
    {
        $statusAttrId = $this->attributeId($connection, 'catalog_product', 'status');
        if ($statusAttrId === null) {
            return;
        }

        $int = $this->table('catalog_product_entity_int');
        $select->join(
            ['status_attr' => $int],
            'status_attr.entity_id = p.entity_id AND status_attr.attribute_id = '.(int) $statusAttrId
                .' AND status_attr.store_id = 0 AND status_attr.value = 1',
            []
        );
    }

    private function joinVisible(AdapterInterface $connection, Select $select): void
    {
        $visibilityAttrId = $this->attributeId($connection, 'catalog_product', 'visibility');
        if ($visibilityAttrId === null) {
            return;
        }

        $int = $this->table('catalog_product_entity_int');
        $select->join(
            ['visibility_attr' => $int],
            'visibility_attr.entity_id = p.entity_id AND visibility_attr.attribute_id = '.(int) $visibilityAttrId
                .' AND visibility_attr.store_id = 0',
            []
        )->where('visibility_attr.value IN (?)', self::VISIBLE_VALUES);
    }

    /**
     * @return list<string>
     */
    private function fetchSkus(AdapterInterface $connection, Select $select): array
    {
        $skus = [];
        foreach ($connection->fetchCol($select) as $sku) {
            $sku = (string) $sku;
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        return $skus;
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
