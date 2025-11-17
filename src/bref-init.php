<?php

use Bref\Bref;
use Bref\LaravelBridge\BrefSubscriber;
use Bref\LaravelBridge\HandlerResolver;

if (! defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

Bref::events()->subscribe(new BrefSubscriber);

Bref::setContainer(static fn() => new HandlerResolver);
