<?php

declare(strict_types=1);

namespace MageWatch\Agent\Console\Command;

use MageWatch\Agent\Model\Config;
use MageWatch\Agent\Model\PayloadBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show MageWatch agent configuration and recent delivery status.
 */
class StatusCommand extends Command
{
    public const NAME = 'magewatch:status';

    public function __construct(
        private readonly Config $config,
        private readonly PayloadBuilder $payloadBuilder
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME);
        $this->setDescription('Show MageWatch agent configuration and recent delivery status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>MageWatch Agent ' . PayloadBuilder::AGENT_VERSION . '</info>');
        $output->writeln('Enabled: ' . ($this->config->isEnabled() ? 'yes' : 'no'));

        $endpoint = $this->config->getEndpointUrl();
        $output->writeln('Endpoint: ' . ($endpoint ?: '(not configured)'));
        $output->writeln('Site token: ' . ($this->config->getSiteToken() ? 'configured' : '(not configured)'));
        $output->writeln('Stuck cron threshold: ' . $this->config->getStuckCronThresholdMinutes() . ' min');

        $collectors = ['indexer', 'cron', 'queue', 'order_stats', 'log', 'system', 'security'];
        $enabled = array_filter($collectors, fn (string $code) => $this->config->isCollectorEnabled($code));
        $output->writeln('Collectors: ' . ($enabled !== [] ? implode(', ', $enabled) : 'none'));

        $logFile = BP . '/var/log/magewatch.log';
        if (is_readable($logFile)) {
            $lines = $this->tailLines($logFile, 5);
            if ($lines !== []) {
                $output->writeln('');
                $output->writeln('<comment>Recent log entries:</comment>');
                foreach ($lines as $line) {
                    $output->writeln('  ' . $line);
                }
            }
        } else {
            $output->writeln('');
            $output->writeln('<comment>No delivery log yet (var/log/magewatch.log).</comment>');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function tailLines(string $path, int $limit): array
    {
        $content = @file($path, FILE_IGNORE_NEW_LINES);
        if ($content === false) {
            return [];
        }

        return array_slice($content, -$limit);
    }
}
