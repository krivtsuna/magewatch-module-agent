<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\ModulePackageResolver;
use MageWatch\Agent\Model\ModuleVersionReader;
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
        private readonly ModulePackageResolver $modulePackageResolver,
        private readonly ModuleVersionReader $moduleVersionReader,
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

        $enabledModules = $this->readEnabledModules($root);

        return [
            'composer' => [
                'packages' => $this->readComposerPackages($root, $enabledModules),
            ],
            'modules' => [
                'enabled' => $enabledModules,
                'installed' => $this->readInstalledModules($enabledModules),
            ],
        ];
    }

    /**
     * @return list<array{name: string, version: string}>
     */
    private function readComposerPackages(string $root, array $enabledModules): array
    {
        $lockPath = $root.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_readable($lockPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($lockPath), true);
        if (! is_array($decoded)) {
            return [];
        }

        $allPackages = [];

        foreach (array_merge($decoded['packages'] ?? [], $decoded['packages-dev'] ?? []) as $package) {
            if (! is_array($package)) {
                continue;
            }

            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;

            if (! is_string($name) || ! is_string($version) || $name === '') {
                continue;
            }

            $allPackages[] = [
                'name' => $name,
                'version' => ltrim($version, 'v'),
            ];
        }

        if ($allPackages === []) {
            return [];
        }

        $prioritized = $this->prioritizedPackageFilters($enabledModules);
        $selected = [];
        $selectedNames = [];

        foreach ($allPackages as $package) {
            if (! $this->isPrioritizedPackage($package['name'], $prioritized)) {
                continue;
            }

            $selected[] = $package;
            $selectedNames[$package['name']] = true;
        }

        foreach ($allPackages as $package) {
            if (count($selected) >= self::MAX_PACKAGES) {
                break;
            }

            if (isset($selectedNames[$package['name']])) {
                continue;
            }

            $selected[] = $package;
            $selectedNames[$package['name']] = true;
        }

        return $selected;
    }

    /**
     * @param  array<string, int>  $enabledModules
     * @return array{exact: array<string, true>, vendor_prefixes: list<string>}
     */
    private function prioritizedPackageFilters(array $enabledModules): array
    {
        $exact = [];
        $vendorPrefixes = [];

        foreach (array_keys($enabledModules) as $moduleName) {
            if (str_starts_with($moduleName, 'Magento_')) {
                continue;
            }

            foreach ($this->modulePackageResolver->packageCandidates($moduleName) as $candidate) {
                $exact[$candidate] = true;
            }

            foreach ($this->modulePackageResolver->vendorPrefixesForModule($moduleName) as $vendorPrefix) {
                $vendorPrefixes[$vendorPrefix] = true;
            }
        }

        return [
            'exact' => $exact,
            'vendor_prefixes' => array_keys($vendorPrefixes),
        ];
    }

    /**
     * @param  array{exact: array<string, true>, vendor_prefixes: list<string>}  $prioritized
     */
    private function isPrioritizedPackage(string $packageName, array $prioritized): bool
    {
        if (isset($prioritized['exact'][$packageName])) {
            return true;
        }

        foreach ($prioritized['vendor_prefixes'] as $vendorPrefix) {
            if (str_starts_with($packageName, $vendorPrefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, int>  $enabledModules
     * @return array<string, array{version: ?string, package: ?string, source: ?string, path: string}>
     */
    private function readInstalledModules(array $enabledModules): array
    {
        $installed = [];

        foreach (array_keys($enabledModules) as $moduleName) {
            if (str_starts_with($moduleName, 'Magento_')) {
                continue;
            }

            $meta = $this->moduleVersionReader->readInstalled($moduleName);
            if ($meta !== null) {
                $installed[$moduleName] = $meta;
            }
        }

        return $installed;
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
