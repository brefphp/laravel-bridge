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

    // @phpstan-ignore-next-line
    $shouldCache = env('BREF_LARAVEL_CACHE_CONFIG', env('APP_ENV') !== 'local');

    if (! $shouldCache) {
        return;
    }

    $defaultConfigCachePath = $laravelRoot . '/bootstrap/cache/config.php';

    // Move the location of the config cache to `/tmp` (because it is writable)
    $newConfigCachePath = StorageDirectories::Path . '/bootstrap/cache/config.php';

    // Automatically caches the configuration if it does not exist (only once)
    if (! file_exists($defaultConfigCachePath) && ! file_exists($newConfigCachePath)) {
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

    $defaultRouteCachePath = $laravelRoot . '/bootstrap/cache/routes-v7.php';

    // Move the location of the route cache to `/tmp` (because it is writable)
    $newRouteCachePath = StorageDirectories::Path . '/bootstrap/cache/routes.php';

    // Automatically caches the routes if it does not exist (only once)
    if (! file_exists($defaultRouteCachePath) && ! file_exists($newRouteCachePath)) {
        $_SERVER['APP_ROUTES_CACHE'] = $_ENV['APP_ROUTES_CACHE'] = $newRouteCachePath;
        putenv("APP_ROUTES_CACHE={$newRouteCachePath}");

        $outputDestination = '> /dev/null';
        if (!getenv('BREF_LARAVEL_OMIT_INITLOG')) {
            fwrite(STDERR, "Running 'php artisan route:cache' to cache the Laravel routes\n");
            // 1>&2 redirects the output to STDERR to avoid messing up HTTP responses with FPM
            $outputDestination = '1>&2';
        }

        passthru("php $laravelRoot/artisan route:cache {$outputDestination}");
    }
});

Bref::setContainer(static fn() => new HandlerResolver);
