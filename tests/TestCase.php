<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests;

use Bref\LaravelBridge\BrefServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BrefServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Avoid booting any real failed-job provider — tests inject their own
        // mock via $this->app->instance(FailedJobProviderInterface::class, …).
        $app['config']->set('queue.failed.driver', 'null');
    }
}
