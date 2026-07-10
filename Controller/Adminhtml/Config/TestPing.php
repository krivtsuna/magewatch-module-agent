<?php

declare(strict_types=1);

namespace MageWatch\Agent\Controller\Adminhtml\Config;

use MageWatch\Agent\Model\Config;
use MageWatch\Agent\Model\PayloadBuilder;
use MageWatch\Agent\Model\Transport\HttpClient;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Builds a live payload against the current saved configuration and
 * posts it to the configured endpoint, returning the outcome as JSON
 * for the "Send Test Ping" admin config button.
 */
class TestPing extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageWatch_Agent::config';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly HttpClient $httpClient
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

        $message = $transportResult->isSuccess()
            ? (string) __('Payload delivered successfully.')
            : (string) ($transportResult->getErrorMessage()
                ?? $transportResult->getResponseBody()
                ?? __('Unknown error'));

        return $result->setData([
            'success' => $transportResult->isSuccess(),
            'status' => $transportResult->getStatusCode(),
            'message' => $message,
        ]);
    }
}
