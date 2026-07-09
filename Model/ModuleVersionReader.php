<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use Magento\Framework\Component\ComponentRegistrar;

/**
 * Reads installed version and Composer package name from a module directory.
 */
class ModuleVersionReader
{
    public function __construct(
        private readonly ComponentRegistrar $componentRegistrar,
    ) {
    }

    /**
     * @return array{version: ?string, package: ?string, source: ?string, path: string}|null
     */
    public function readInstalled(string $moduleName): ?array
    {
        $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);

        if ($modulePath === null || $modulePath === '') {
            return null;
        }

        $package = null;
        $version = null;
        $source = null;

        $composerJsonPath = $modulePath.DIRECTORY_SEPARATOR.'composer.json';
        if (is_readable($composerJsonPath)) {
            $decoded = json_decode((string) file_get_contents($composerJsonPath), true);
            if (is_array($decoded)) {
                if (is_string($decoded['name'] ?? null) && $decoded['name'] !== '') {
                    $package = $decoded['name'];
                }
                if (is_string($decoded['version'] ?? null) && $decoded['version'] !== '') {
                    $version = ltrim($decoded['version'], 'v');
                    $source = 'composer.json';
                }
            }
        }

        if ($version === null) {
            $moduleXmlPath = $modulePath.DIRECTORY_SEPARATOR.'etc'.DIRECTORY_SEPARATOR.'module.xml';
            if (is_readable($moduleXmlPath)) {
                $xml = @simplexml_load_file($moduleXmlPath);
                if ($xml !== false) {
                    $attributes = $xml->module->attributes();
                    $setupVersion = is_object($attributes) ? (string) ($attributes['setup_version'] ?? '') : '';
                    if ($setupVersion !== '') {
                        $version = $setupVersion;
                        $source = 'module.xml';
                    }
                }
            }
        }

        if ($package === null && $version === null) {
            return null;
        }

        return [
            'version' => $version,
            'package' => $package,
            'source' => $source,
            'path' => str_contains($modulePath, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR) ? 'vendor' : 'app/code',
        ];
    }
}
