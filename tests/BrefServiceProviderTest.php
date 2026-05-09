<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests;

use Bref\LaravelBridge\BrefServiceProvider;
use Bref\LaravelBridge\StorageDirectories;
use Bref\Monolog\CloudWatchFormatter;
use Orchestra\Testbench\TestCase;

class BrefServiceProviderTest extends TestCase
{
    private string $defaultEmergencyPath;

    protected function setUp(): void
    {
        $_SERVER['LAMBDA_TASK_ROOT'] = __DIR__;

        parent::setUp();

        $this->defaultEmergencyPath = $this->app->storagePath('logs/laravel.log');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LAMBDA_TASK_ROOT']);

        parent::tearDown();
    }

    public function testItUsesStderrForDefaultEmergencyLoggingOnLambda(): void
    {
        config()->set('logging.default', 'stack');
        config()->set('logging.channels.stderr.formatter', null);
        config()->set('logging.channels.emergency', [
            'path' => $this->defaultEmergencyPath,
        ]);

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame(StorageDirectories::Path, $this->app->storagePath());
        $this->assertSame('stderr', config('logging.default'));
        $this->assertSame(CloudWatchFormatter::class, config('logging.channels.stderr.formatter'));
        $this->assertSame('php://stderr', config('logging.channels.emergency.path'));
    }

    public function testItPreservesCustomEmergencyLoggingPathOnLambda(): void
    {
        config()->set('logging.channels.emergency', [
            'path' => '/tmp/emergency.log',
        ]);

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame('/tmp/emergency.log', config('logging.channels.emergency.path'));
    }
}
