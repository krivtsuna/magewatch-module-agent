<?php

declare(strict_types=1);

namespace MageWatch\Agent\Plugin\App;

use MageWatch\Agent\Model\HeartbeatDelivery;
use Magento\Framework\App\MaintenanceMode;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Push a heartbeat to MageWatch immediately when maintenance mode toggles —
 * do not wait for the next minute cron.
 */
class MaintenanceModePlugin
{
    public function __construct(
        private readonly HeartbeatDelivery $heartbeatDelivery,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param  mixed  $result
     * @return mixed
     */
    public function afterEnable(MaintenanceMode $subject, $result = null)
    {
        $this->notify(true);

        return $result;
    }

    /**
     * @param  mixed  $result
     * @return mixed
     */
    public function afterDisable(MaintenanceMode $subject, $result = null)
    {
        $this->notify(false);

        return $result;
    }

    private function notify(bool $maintenanceOn): void
    {
        try {
            $this->heartbeatDelivery->sendMaintenanceStatePing($maintenanceOn);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'MageWatch maintenance ping failed (maintenance=%s): %s',
                $maintenanceOn ? 'on' : 'off',
                $e->getMessage()
            ));
        }
    }
}
