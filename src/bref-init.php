<?php

use Bref\Bref;

use CacheWerk\BrefLaravelBridge\HandlerResolver;
use CacheWerk\BrefLaravelBridge\StorageDirectories;

Bref::beforeStartup(static function () {
    if (! defined('STDERR')) {
        define('STDERR', fopen('php://stderr', 'wb'));
    }

    StorageDirectories::create();

    $defaultConfigCachePath = $_SERVER['LAMBDA_TASK_ROOT'] . '/bootstrap/cache/config.php';

    if (file_exists($defaultConfigCachePath)) {
        return;
    }

    // Move the location of the config cache to `/tmp` (because it is writable)
    $newConfigCachePath = StorageDirectories::Path . '/bootstrap/cache/config.php';

    // Automatically caches the configuration if it does not exist (only once)
    if (! file_exists($newConfigCachePath)) {
        $_SERVER['APP_CONFIG_CACHE'] = $_ENV['APP_CONFIG_CACHE'] = $newConfigCachePath;
        putenv("APP_CONFIG_CACHE={$newConfigCachePath}");

        fwrite(STDERR, "Running 'php artisan config:cache' to cache the Laravel configuration\n");

        // 1>&2 redirects the output to STDERR to avoid messing up HTTP responses with FPM
        passthru('php artisan config:cache 1>&2');
    }
});

Bref::setContainer(static fn() => new HandlerResolver);
