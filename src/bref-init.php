<?php

use Bref\Bref;
use Bref\LaravelBridge\LaravelPathFinder;
use Bref\LaravelBridge\HandlerResolver;
use Bref\LaravelBridge\MaintenanceMode;
use Bref\LaravelBridge\StorageDirectories;

Bref::beforeStartup(static function () {
    if (! defined('STDERR')) {
        define('STDERR', fopen('php://stderr', 'wb'));
    }

    $laravelRoot = LaravelPathFinder::root();

    StorageDirectories::create();

    MaintenanceMode::setUp();

    // Move the location of the PsySH config cache to `/tmp` (because it is writable)
    $xdgHome = StorageDirectories::Path . '/psysh';
    $_SERVER['XDG_CONFIG_HOME'] = $_ENV['XDG_CONFIG_HOME'] = $xdgHome;
    putenv("XDG_CONFIG_HOME=$xdgHome");

    $shouldCache = env('BREF_LARAVEL_CACHE_CONFIG', env('APP_ENV') !== 'local');

    if (! $shouldCache) {
        return;
    }

    $defaultConfigCachePath = $laravelRoot . '/bootstrap/cache/config.php';

    if (file_exists($defaultConfigCachePath)) {
        return;
    }

    // Move the location of the config cache to `/tmp` (because it is writable)
    $newConfigCachePath = StorageDirectories::Path . '/bootstrap/cache/config.php';

    // Automatically caches the configuration if it does not exist (only once)
    if (! file_exists($newConfigCachePath)) {
        $_SERVER['APP_CONFIG_CACHE'] = $_ENV['APP_CONFIG_CACHE'] = $newConfigCachePath;
        putenv("APP_CONFIG_CACHE={$newConfigCachePath}");

        $outputDestination = '> /dev/null';
        if (!getenv('BREF_LARAVEL_OMIT_INITLOG')) {
            fwrite(STDERR, "Running 'php artisan config:cache' to cache the Laravel configuration\n");
            // 1>&2 redirects the output to STDERR to avoid messing up HTTP responses with FPM
            $outputDestination = '1>&2';
        }

        passthru("php $laravelRoot/artisan config:cache {$outputDestination}");
    }
});

Bref::setContainer(static fn() => new HandlerResolver);
