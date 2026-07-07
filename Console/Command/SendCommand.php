<?php

declare(strict_types=1);

namespace MageWatch\Agent\Console\Command;

use MageWatch\Agent\Model\Config;
use MageWatch\Agent\Model\PayloadBuilder;
use MageWatch\Agent\Model\Transport\HttpClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Collect metrics and send a heartbeat to MageWatch immediately.
 */
class SendCommand extends Command
{
    public const NAME = 'magewatch:send';

    public function __construct(
        private readonly Config $config,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly HttpClient $httpClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME);
        $this->setDescription('Collect metrics and send a heartbeat to MageWatch');
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Send even when the agent is disabled in configuration'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');

        if (!$this->config->isEnabled() && !$force) {
            $output->writeln('<comment>MageWatch agent is disabled. Use --force to send anyway.</comment>');

            return Command::FAILURE;
        }

        $endpointUrl = $this->config->getEndpointUrl();
        $siteToken = $this->config->getSiteToken();

        if (!$endpointUrl || !$siteToken) {
            $output->writeln('<error>Endpoint URL and site token must be configured.</error>');

            return Command::FAILURE;
        }

        $payload = $this->payloadBuilder->build();
        $result = $this->httpClient->send($endpointUrl, $siteToken, $payload);

        if (!$result->isSuccess()) {
            $status = $result->getStatusCode() !== null ? (string) $result->getStatusCode() : 'n/a';
            $message = $result->getErrorMessage() ?? $result->getResponseBody() ?? 'unknown error';
            $output->writeln(sprintf('<error>Delivery failed (HTTP %s): %s</error>', $status, $message));

            return Command::FAILURE;
        }

        $status = $result->getStatusCode() ?? 200;
        $output->writeln(sprintf('<info>OK — heartbeat sent (HTTP %d)</info>', $status));

        if (!empty($payload['collector_errors'])) {
            $output->writeln('<comment>Collector warnings:</comment>');
            foreach ($payload['collector_errors'] as $error) {
                $output->writeln('  - ' . $error);
            }
        }

        return Command::SUCCESS;
    }
}
