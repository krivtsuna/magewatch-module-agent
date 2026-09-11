<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Bounded pub/ walk for the 5-minute heartbeat.
 *
 * A full RecursiveDirectoryIterator over pub/media (or pub/static) stats every
 * product image on NFS/EFS and can stall PHP-FPM. This walker skips known
 * image/cache trees and stops after a hard inode / depth / PHP-file budget.
 */
class PubTreeScanner
{
    public const DEFAULT_MAX_INODES = 2500;

    public const DEFAULT_MAX_PHP = 80;

    public const DEFAULT_MAX_DEPTH = 8;

    /**
     * Directory names that are never worth walking on a heartbeat.
     * `product` / `category` are Magento image trees under media/catalog/.
     *
     * @var list<string>
     */
    public const SKIP_DIR_NAMES = [
        'cache',
        'captcha',
        'category',
        'custom_options',
        'customer',
        'downloadable',
        'import',
        'product',
        'sitemap',
        'static',
        'theme',
        'theme_customization',
        'thumbnails',
        '.thumbs',
        'var',
    ];

    /**
     * @return list<string> Absolute paths of .php files found
     */
    public function listPhpFiles(
        string $root,
        int $maxInodes = self::DEFAULT_MAX_INODES,
        int $maxPhp = self::DEFAULT_MAX_PHP,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ): array {
        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! is_dir($root)) {
            return [];
        }

        $php = [];
        $inodes = 0;
        $this->walk($root, 0, $php, $inodes, $maxInodes, $maxPhp, $maxDepth);

        return $php;
    }

    /**
     * @param  list<string>  $php
     */
    private function walk(
        string $dir,
        int $depth,
        array &$php,
        int &$inodes,
        int $maxInodes,
        int $maxPhp,
        int $maxDepth,
    ): bool {
        if ($inodes >= $maxInodes || count($php) >= $maxPhp) {
            return false;
        }

        if ($depth > $maxDepth) {
            return true;
        }

        $handle = @opendir($dir);
        if ($handle === false) {
            return true;
        }

        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (++$inodes > $maxInodes) {
                closedir($handle);

                return false;
            }

            $path = $dir.$name;
            if (is_dir($path) && ! is_link($path)) {
                if ($this->shouldSkipDir($name)) {
                    continue;
                }

                if (! $this->walk(
                    $path.DIRECTORY_SEPARATOR,
                    $depth + 1,
                    $php,
                    $inodes,
                    $maxInodes,
                    $maxPhp,
                    $maxDepth,
                )) {
                    closedir($handle);

                    return false;
                }

                continue;
            }

            if (! is_file($path) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $php[] = $path;
            if (count($php) >= $maxPhp) {
                closedir($handle);

                return false;
            }
        }

        closedir($handle);

        return true;
    }

    private function shouldSkipDir(string $name): bool
    {
        return in_array(strtolower($name), self::SKIP_DIR_NAMES, true);
    }
}
