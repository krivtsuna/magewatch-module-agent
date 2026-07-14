<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Plugin\App;

use MageWatch\Agent\Model\HeartbeatDelivery;
use MageWatch\Agent\Plugin\App\MaintenanceModePlugin;
use Magento\Framework\App\MaintenanceMode;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MaintenanceModePluginTest extends TestCase
{
    private HeartbeatDelivery&MockObject $heartbeatDelivery;

    private LoggerInterface&MockObject $logger;

    private MaintenanceModePlugin $plugin;

    protected function setUp(): void
    {
        $this->heartbeatDelivery = $this->createMock(HeartbeatDelivery::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->plugin = new MaintenanceModePlugin($this->heartbeatDelivery, $this->logger);
    }

    public function test_after_set_true_sends_maintenance_on_ping(): void
    {
        $subject = $this->createMock(MaintenanceMode::class);

        $this->heartbeatDelivery
            ->expects($this->once())
            ->method('sendMaintenanceStatePing')
            ->with(true);

        $this->assertTrue($this->plugin->afterSet($subject, true, true));
    }

    public function test_after_set_false_sends_maintenance_off_ping(): void
    {
        $subject = $this->createMock(MaintenanceMode::class);

        $this->heartbeatDelivery
            ->expects($this->once())
            ->method('sendMaintenanceStatePing')
            ->with(false);

        $this->assertTrue($this->plugin->afterSet($subject, true, false));
    }
}
