<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use MageWatch\Agent\Model\Attribution\OrderAttributionRecorder;
use MageWatch\Agent\Observer\RecordOrderAttribution;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecordOrderAttributionTest extends TestCase
{
    private OrderAttributionRecorder&MockObject $recorder;

    private LoggerInterface&MockObject $logger;

    private RecordOrderAttribution $observer;

    protected function setUp(): void
    {
        $this->recorder = $this->createMock(OrderAttributionRecorder::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new RecordOrderAttribution($this->recorder, $this->logger);
    }

    public function test_records_single_order(): void
    {
        $this->recorder->expects($this->once())
            ->method('recordForOrder')
            ->with(15, '000000015', 1);

        $this->observer->execute($this->observerFor(['order' => $this->order(15, '000000015', 1)]));
    }

    public function test_records_multishipping_orders(): void
    {
        $this->recorder->expects($this->exactly(2))
            ->method('recordForOrder')
            ->withConsecutive(
                [21, '000000021', 1],
                [22, '000000022', 2]
            );

        $this->observer->execute($this->observerFor([
            'orders' => [
                $this->order(21, '000000021', 1),
                $this->order(22, '000000022', 2),
            ],
        ]));
    }

    public function test_skips_unsaved_order(): void
    {
        $this->recorder->expects($this->never())->method('recordForOrder');

        $this->observer->execute($this->observerFor(['order' => $this->order(0, null, 1)]));
    }

    public function test_swallows_recorder_errors(): void
    {
        $this->recorder->method('recordForOrder')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('warning');

        $this->observer->execute($this->observerFor(['order' => $this->order(9, '9', 1)]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function observerFor(array $data): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->willReturnCallback(
            static fn (string $key) => $data[$key] ?? null
        );

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    private function order(int $id, ?string $incrementId, int $storeId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($id > 0 ? $id : null);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getStoreId')->willReturn($storeId);

        return $order;
    }
}
