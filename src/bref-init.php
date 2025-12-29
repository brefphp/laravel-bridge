<?php

use Bref\Bref;
use Bref\LaravelBridge\BrefSubscriber;
use Bref\LaravelBridge\HandlerResolver;

if (! defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

// Print a warning if Laravel Vapor is detected because it is incompatible with Bref
if (class_exists('Laravel\Vapor\VaporServiceProvider')) {
    fwrite(STDERR, "WARNING: `laravel/vapor-core` package detected. Laravel Vapor is incompatible with Bref. Please remove the 'laravel/vapor-core' package from your project to avoid unexpected issues.\n");
}

Bref::events()->subscribe(new BrefSubscriber);

Bref::setContainer(static fn() => new HandlerResolver);
