<?php

declare(strict_types=1);

namespace MageWatch\Agent\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use MageWatch\Agent\Model\Attribution\OrderAttributionRecorder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Attaches captured campaign data to a newly placed storefront order.
 *
 * Listens to checkout_submit_all_after (not sales_order_place_after): the
 * latter fires before the order row exists, so entity_id is still empty.
 * Multishipping sends `orders`; one-page / GraphQL send `order`.
 */
class RecordOrderAttribution implements ObserverInterface
{
    public function __construct(
        private readonly OrderAttributionRecorder $recorder,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        try {
            foreach ($this->ordersFrom($observer) as $order) {
                $orderId = (int) $order->getEntityId();
                if ($orderId <= 0) {
                    continue;
                }

                $incrementId = $order->getIncrementId();
                $this->recorder->recordForOrder(
                    $orderId,
                    $incrementId !== null && $incrementId !== '' ? (string) $incrementId : null,
                    (int) $order->getStoreId()
                );
            }
        } catch (Throwable $e) {
            $this->logger->warning('MageWatch attribution observer failed: '.$e->getMessage());
        }
    }

    /**
     * @return list<OrderInterface>
     */
    private function ordersFrom(Observer $observer): array
    {
        $orders = $observer->getEvent()->getData('orders');
        if (is_array($orders) && $orders !== []) {
            return array_values(array_filter(
                $orders,
                static fn ($order): bool => $order instanceof OrderInterface
            ));
        }

        $order = $observer->getEvent()->getData('order');

        return $order instanceof OrderInterface ? [$order] : [];
    }
}
