<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Http;

use Bref\LaravelBridge\BrefServiceProvider;
use Bref\LaravelBridge\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class ForwardedHostTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['LAMBDA_TASK_ROOT'] = __DIR__;

        parent::setUp();

        // Laravel 10 applications add it in their own HTTP kernel
        $kernel = $this->app->make(Kernel::class);
        if (! $kernel->hasMiddleware(TrustProxies::class)) {
            $kernel->prependMiddleware(TrustProxies::class);
        }

        Route::get('/url', fn () => url('/path'));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LAMBDA_TASK_ROOT']);

        if (method_exists(TrustProxies::class, 'flushState')) {
            TrustProxies::flushState();
        }

        parent::tearDown();
    }

    public function testTheHeadersAreIgnoredOnACustomDomain(): void
    {
        foreach (['app.example.com', 'amazonaws.com.example.com', 'fakeamazonaws.com', 'example.on.aws.example.com'] as $host) {
            $this->assertSame("https://$host/path", $this->url($host));
        }
    }

    public function testTheHeadersAreUsedOnAnAwsDomain(): void
    {
        foreach (['abc123.execute-api.eu-west-1.amazonaws.com', 'abc123.lambda-url.eu-west-1.on.aws'] as $host) {
            $this->assertSame('https://cdn.example.org/prefix/path', $this->url($host));
        }
    }

    public function testTheTrustedProxiesOfTheApplicationAreKept(): void
    {
        config()->set('trustedproxy.proxies', '*');
        (new BrefServiceProvider($this->app))->register();

        $this->assertSame('*', config('trustedproxy.proxies'));
        $this->assertSame('https://cdn.example.org/prefix/path', $this->url('app.example.com'));
    }

    public function testTheHeadersAreUsedWhenTheApplicationTrustsProxies(): void
    {
        if (! method_exists(TrustProxies::class, 'at')) {
            $this->markTestSkipped('TrustProxies::at() was added in Laravel 11');
        }

        TrustProxies::at('*');

        $this->assertSame('https://cdn.example.org/prefix/path', $this->url('app.example.com'));
    }

    public function testTheHeadersAreUsedWhenTheApplicationChoosesTheTrustedHeaders(): void
    {
        if (! method_exists(TrustProxies::class, 'withHeaders')) {
            $this->markTestSkipped('TrustProxies::withHeaders() was added in Laravel 11');
        }

        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX);

        $this->assertSame('https://cdn.example.org/prefix/path', $this->url('app.example.com'));
    }

    private function url(string $host): string
    {
        // Under PHP-FPM, Bref sets REMOTE_ADDR to 127.0.0.1
        $request = Request::create("https://$host/url", 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'cdn.example.org',
            'HTTP_X_FORWARDED_PREFIX' => '/prefix',
        ]);

        return $this->app->make(Kernel::class)->handle($request)->getContent();
    }
}
