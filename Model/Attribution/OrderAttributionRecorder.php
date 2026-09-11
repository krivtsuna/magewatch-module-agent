<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Attribution;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\CookieManagerInterface;
use MageWatch\Agent\Model\Config;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists first/last-touch campaign data against a placed order.
 *
 * Writes only to magewatch_order_attribution — never to sales_order. Missing
 * cookies, a missing table, or a duplicate row are all no-ops so checkout
 * cannot fail because of attribution.
 */
class OrderAttributionRecorder
{
    public const TABLE = 'magewatch_order_attribution';

    public function __construct(
        private readonly Config $config,
        private readonly CookieManagerInterface $cookieManager,
        private readonly AttributionCookie $cookie,
        private readonly TouchNormalizer $normalizer,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {}

    public function recordForOrder(int $orderId, ?string $incrementId, int $storeId): void
    {
        if ($orderId <= 0 || ! $this->config->isEnabled() || ! $this->config->isAttributionEnabled()) {
            return;
        }

        try {
            $this->persist($orderId, $incrementId, $storeId);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'MageWatch attribution could not be saved for order %d: %s',
                $orderId,
                $e->getMessage()
            ));
        }
    }

    private function persist(int $orderId, ?string $incrementId, int $storeId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        if (! $connection->isTableExists($table)) {
            return;
        }

        $parsed = $this->cookie->parse($this->cookieManager->getCookie(AttributionCookie::NAME));
        if ($parsed === null) {
            return;
        }

        $existing = $connection->fetchOne(
            $connection->select()
                ->from($table, ['order_id'])
                ->where('order_id = ?', $orderId)
        );
        if ($existing !== false && $existing !== null && $existing !== '') {
            return;
        }

        $first = $this->normalizer->normalize($parsed['first']);
        $last = $this->normalizer->normalize($parsed['last']);

        $connection->insert($table, [
            'order_id' => $orderId,
            'increment_id' => $this->incrementId($incrementId),
            'store_id' => $storeId,
            'first_source' => $first['source'],
            'first_medium' => $first['medium'],
            'first_campaign' => $first['campaign'],
            'first_term' => $first['term'],
            'first_content' => $first['content'],
            'first_referrer_host' => $first['referrer_host'],
            'first_landing_path' => $first['landing_path'],
            'first_touch_at' => $first['touched_at'],
            'last_source' => $last['source'],
            'last_medium' => $last['medium'],
            'last_campaign' => $last['campaign'],
            'last_term' => $last['term'],
            'last_content' => $last['content'],
            'last_referrer_host' => $last['referrer_host'],
            'last_landing_path' => $last['landing_path'],
            'last_touch_at' => $last['touched_at'],
            'click_id_type' => $last['click_id_type'],
            'click_id' => $last['click_id'],
        ]);
    }

    private function incrementId(?string $incrementId): ?string
    {
        if ($incrementId === null || $incrementId === '') {
            return null;
        }

        return mb_substr($incrementId, 0, 50);
    }
}
