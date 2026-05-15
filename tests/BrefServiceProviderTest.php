<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests;

use Bref\LaravelBridge\BrefServiceProvider;
use Bref\LaravelBridge\StorageDirectories;
use Bref\Monolog\CloudWatchFormatter;
use Orchestra\Testbench\TestCase;

class BrefServiceProviderTest extends TestCase
{
    private string $defaultEmergencyLogPath;

    protected function setUp(): void
    {
        $_SERVER['LAMBDA_TASK_ROOT'] = __DIR__;

        parent::setUp();

        $this->defaultEmergencyLogPath = $this->app->storagePath('logs/laravel.log');
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
            'path' => $this->defaultEmergencyLogPath,
        ]);

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame(StorageDirectories::Path, $this->app->storagePath());
        $this->assertSame('stderr', config('logging.default'));
        $this->assertSame(CloudWatchFormatter::class, config('logging.channels.stderr.formatter'));
        $this->assertSame(config('logging.channels.stderr'), config('logging.channels.emergency'));
    }

    public function testItPreservesCustomEmergencyLoggingPathOnLambda(): void
    {
        config()->set('logging.channels.emergency', [
            'path' => '/custom/logs/laravel.log',
        ]);

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame('/custom/logs/laravel.log', config('logging.channels.emergency.path'));
    }

    public function testItPreservesNonFileEmergencyLoggingOnLambda(): void
    {
        config()->set('logging.channels.emergency', [
            'driver' => 'slack',
            'url' => 'https://example.com/logs',
        ]);

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame([
            'driver' => 'slack',
            'url' => 'https://example.com/logs',
        ], config('logging.channels.emergency'));
    }
}
