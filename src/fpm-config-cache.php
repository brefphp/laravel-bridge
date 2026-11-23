<?php

declare(strict_types=1);

/**
 * Runs `artisan config:cache` inside PHP-FPM.
 *
 * Executed by PHP-FPM during the cold start, see `Bref\LaravelBridge\BrefSubscriber::cacheConfigWithFpm()`.
 * The parameters come from that FastCGI request, they are not HTTP input: Bref's PHP-FPM only
 * listens on a local Unix socket.
 */

if (PHP_SAPI !== 'fpm-fcgi' || ! isset($_SERVER['BREF_LARAVEL_ROOT'], $_SERVER['BREF_LARAVEL_AUTOLOADER'])) {
    http_response_code(404);
    return;
}

require $_SERVER['BREF_LARAVEL_AUTOLOADER'];

$app = require $_SERVER['BREF_LARAVEL_ROOT'] . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$exitCode = $kernel->call('config:cache');

header('X-Bref-Exit-Code: ' . $exitCode);
echo $kernel->output();
