<?php

declare(strict_types=1);

/**
 * Autoload only MageWatch\\Agent classes so attribution unit tests can run
 * without magento/framework installed in this package.
 */
require_once __DIR__.'/MagentoStubs.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'MageWatch\\Agent\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__).'/'.$relative.'.php';
    if (is_file($file)) {
        require $file;
    }
});
