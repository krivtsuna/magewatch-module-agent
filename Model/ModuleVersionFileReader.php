<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Reads version metadata from common Magento module directory layouts.
 */
class ModuleVersionFileReader
{
    /**
     * @return array{version: ?string, package: ?string, source: ?string}|null
     */
    public function readFromPath(string $modulePath): ?array
    {
        if ($modulePath === '' || ! is_dir($modulePath)) {
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

        $versionJsonPath = $modulePath.DIRECTORY_SEPARATOR.'version.json';
        if ($version === null && is_readable($versionJsonPath)) {
            $decoded = json_decode((string) file_get_contents($versionJsonPath), true);
            if (is_array($decoded)) {
                if (is_string($decoded['package_name'] ?? null) && $decoded['package_name'] !== '') {
                    $package = $decoded['package_name'];
                }
                if (is_string($decoded['version'] ?? null) && $decoded['version'] !== '') {
                    $version = ltrim($decoded['version'], 'v');
                    $source = 'version.json';
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

        if ($version === null) {
            $registrationPath = $modulePath.DIRECTORY_SEPARATOR.'registration.php';
            if (is_readable($registrationPath)) {
                $fromRegistration = $this->readRegistrationDocblockVersion((string) file_get_contents($registrationPath));
                if ($fromRegistration !== null) {
                    $version = $fromRegistration;
                    $source = 'registration.php';
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
        ];
    }

    private function readRegistrationDocblockVersion(string $contents): ?string
    {
        if (preg_match('/@version\s+([0-9]+(?:\.[0-9]+)*)/i', $contents, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
