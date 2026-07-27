<?php

declare(strict_types=1);

namespace MageWatch\Agent\Controller\Adminhtml\Config;

use MageWatch\Agent\Model\Config;
use MageWatch\Agent\Model\HeartbeatDelivery;
use MageWatch\Agent\Model\PayloadBuilder;
use MageWatch\Agent\Model\Transport\HttpClient;
use MageWatch\Agent\Model\Transport\ResponseMessageFormatter;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Verifies connectivity, then immediately ships a full metrics snapshot so
 * MageWatch SaaS has data without waiting for the next cron cycle.
 */
class TestPing extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageWatch_Agent::config';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly HttpClient $httpClient,
        private readonly HeartbeatDelivery $heartbeatDelivery,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        $endpointUrl = $this->config->getEndpointUrl();
        $siteToken = $this->config->getSiteToken();

        if (!$endpointUrl || !$siteToken) {
            return $result->setData([
                'success' => false,
                'status' => null,
                'message' => (string) __('Endpoint URL and Site Token must be configured and saved first.'),
            ]);
        }

        $payload = $this->payloadBuilder->buildTestPing();
        $transportResult = $this->httpClient->send($endpointUrl, $siteToken, $payload);

        if (!$transportResult->isSuccess()) {
            $message = (string) (ResponseMessageFormatter::forAdmin(
                $transportResult->getStatusCode(),
                $transportResult->getErrorMessage() ?? $transportResult->getResponseBody()
            ) ?? __('Unknown error'));

            return $result->setData([
                'success' => false,
                'status' => $transportResult->getStatusCode(),
                'message' => $message,
            ]);
        }

        $snapshotOk = $this->heartbeatDelivery->sendFullNow();
        $message = $snapshotOk
            ? (string) __('Connected — first snapshot delivered to MageWatch.')
            : (string) __('Connected, but the first full snapshot failed. Cron will retry shortly.');

        return $result->setData([
            'success' => true,
            'status' => $transportResult->getStatusCode(),
            'message' => $message,
            'snapshot_delivered' => $snapshotOk,
        ]);
    }
}
