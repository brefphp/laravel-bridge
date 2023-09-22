<?php

use Bref\Bref;

use Bref\LaravelBridge\HandlerResolver;
use Bref\LaravelBridge\MaintenanceMode;
use Bref\LaravelBridge\StorageDirectories;

Bref::beforeStartup(static function () {
    $laravelHome = __DIR__ . '/../../../../';
    
    if (! defined('STDERR')) {
        define('STDERR', fopen('php://stderr', 'wb'));
    }

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

    $defaultConfigCachePath = $laravelHome . '/bootstrap/cache/config.php';

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
        passthru("php {$laravelHome}artisan config:cache 1>&2");
    }
});

Bref::setContainer(static fn() => new HandlerResolver);
