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
        private readonly ModuleVersionFileReader $versionFileReader,
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

        $meta = $this->versionFileReader->readFromPath($modulePath);

        if ($meta === null) {
            return null;
        }

        return [
            'version' => $meta['version'],
            'package' => $meta['package'],
            'source' => $meta['source'],
            'path' => str_contains($modulePath, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR) ? 'vendor' : 'app/code',
        ];
    }
}
