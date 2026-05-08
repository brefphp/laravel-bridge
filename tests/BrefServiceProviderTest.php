<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests;

use Bref\LaravelBridge\BrefServiceProvider;
use Bref\LaravelBridge\StorageDirectories;
use Bref\Monolog\CloudWatchFormatter;
use Orchestra\Testbench\TestCase;

class BrefServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['LAMBDA_TASK_ROOT'] = __DIR__;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LAMBDA_TASK_ROOT']);

        parent::tearDown();
    }

    public function testItUsesStderrForDefaultAndEmergencyLoggingOnLambda(): void
    {
        config()->set('logging.default', 'stack');
        config()->set('logging.channels.stderr.formatter', null);
        config()->set('logging.channels.emergency', [
            'path' => '/custom/logs/laravel.log',
        ]);

        new BrefServiceProvider($this->app)->register();

        $this->assertSame(StorageDirectories::Path, $this->app->storagePath());
        $this->assertSame('stderr', config('logging.default'));
        $this->assertSame(CloudWatchFormatter::class, config('logging.channels.stderr.formatter'));
        $this->assertSame(config('logging.channels.stderr'), config('logging.channels.emergency'));
    }
}
