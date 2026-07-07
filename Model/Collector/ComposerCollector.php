<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * Sends composer.lock packages and enabled Magento modules for vulnerability checks.
 */
class ComposerCollector implements CollectorInterface
{
    private const CODE = 'composer';

    private const MAX_PACKAGES = 400;

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $root = rtrim(
            $this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getAbsolutePath(),
            DIRECTORY_SEPARATOR
        );

        return [
            'composer' => [
                'packages' => $this->readComposerPackages($root),
            ],
            'modules' => [
                'enabled' => $this->readEnabledModules($root),
            ],
        ];
    }

    /**
     * @return list<array{name: string, version: string}>
     */
    private function readComposerPackages(string $root): array
    {
        $lockPath = $root.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_readable($lockPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($lockPath), true);
        if (! is_array($decoded)) {
            return [];
        }

        $packages = [];

        foreach (array_merge($decoded['packages'] ?? [], $decoded['packages-dev'] ?? []) as $package) {
            if (! is_array($package)) {
                continue;
            }

            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;

            if (! is_string($name) || ! is_string($version) || $name === '') {
                continue;
            }

            $packages[] = [
                'name' => $name,
                'version' => ltrim($version, 'v'),
            ];

            if (count($packages) >= self::MAX_PACKAGES) {
                break;
            }
        }

        return $packages;
    }

    /**
     * @return array<string, int>
     */
    private function readEnabledModules(string $root): array
    {
        $configPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'etc'.DIRECTORY_SEPARATOR.'config.php';

        if (! is_readable($configPath)) {
            return [];
        }

        $config = require $configPath;

        if (! is_array($config) || ! is_array($config['modules'] ?? null)) {
            return [];
        }

        $enabled = [];

        foreach ($config['modules'] as $name => $status) {
            if ((int) $status === 1) {
                $enabled[(string) $name] = 1;
            }
        }

        return $enabled;
    }
}
