<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Validates PHP files under pub/ against Magento core expectations.
 *
 * @see https://github.com/magento/magento2-base/tree/2.4-develop/pub
 */
class PubPhpIntegrityChecker
{
    private const MAGENTO_BASE_RELATIVE = 'vendor/magento/magento2-base';

    /** @var list<string> Legitimate top-level pub/*.php shipped with Magento 2. */
    private const ALLOWED_PUB_ROOT_PHP = [
        'index.php',
        'cron.php',
        'static.php',
        'get.php',
        'health_check.php',
    ];

    /** @var list<string> Directories under pub/ whose PHP files ship with core. */
    private const ALLOWED_PUB_PHP_DIRS = [
        'errors',
    ];

    /**
     * @return array{unexpected_pub_php: list<string>, core_pub_php_modified: list<string>}
     */
    public function scan(string $pubPath, string $magentoRoot): array
    {
        $pubPath = rtrim($pubPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $magentoRoot = rtrim($magentoRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $basePubPath = $magentoRoot.self::MAGENTO_BASE_RELATIVE.DIRECTORY_SEPARATOR.'pub'.DIRECTORY_SEPARATOR;

        $unexpected = [];
        $modified = [];

        foreach ($this->listPhpFilesInDirectory($pubPath) as $file) {
            $basename = basename($file);
            $relative = $this->relativePubPath($pubPath, $file);

            if (in_array($basename, self::ALLOWED_PUB_ROOT_PHP, true)) {
                if ($this->differsFromBase($file, $basePubPath.$basename)) {
                    $modified[] = $relative;
                }

                continue;
            }

            $unexpected[] = $relative;
        }

        foreach (self::ALLOWED_PUB_PHP_DIRS as $dir) {
            $dirPath = $pubPath.$dir.DIRECTORY_SEPARATOR;
            if (! is_dir($dirPath)) {
                continue;
            }

            foreach ($this->listPhpFilesRecursive($dirPath) as $file) {
                $relative = $this->relativePubPath($pubPath, $file);
                $baseFile = $basePubPath.str_replace('/', DIRECTORY_SEPARATOR, substr($relative, 4));

                if ($this->differsFromBase($file, $baseFile)) {
                    $modified[] = $relative;
                }
            }
        }

        $mediaPath = $pubPath.'media'.DIRECTORY_SEPARATOR;
        if (is_dir($mediaPath)) {
            foreach ($this->listPhpFilesRecursive($mediaPath) as $file) {
                $unexpected[] = $this->relativePubPath($pubPath, $file);
            }
        }

        return [
            'unexpected_pub_php' => array_values(array_unique($unexpected)),
            'core_pub_php_modified' => array_values(array_unique($modified)),
        ];
    }

    private function differsFromBase(string $actualFile, string $baseFile): bool
    {
        if (! is_readable($baseFile)) {
            return false;
        }

        $actualHash = @hash_file('sha256', $actualFile);
        $baseHash = @hash_file('sha256', $baseFile);

        if (! is_string($actualHash) || ! is_string($baseHash)) {
            return false;
        }

        return ! hash_equals($baseHash, $actualHash);
    }

    /**
     * @return list<string>
     */
    private function listPhpFilesInDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.'*.php') ?: [];

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * @return list<string>
     */
    private function listPhpFilesRecursive(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }

    private function relativePubPath(string $pubPath, string $absolutePath): string
    {
        $relative = ltrim(str_replace($pubPath, '', $absolutePath), DIRECTORY_SEPARATOR);

        return 'pub/'.$relative;
    }
}
