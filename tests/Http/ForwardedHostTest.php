<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Http;

use Bref\Context\Context;
use Bref\Event\Http\HttpRequestEvent;
use Bref\LaravelBridge\BrefServiceProvider;
use Bref\LaravelBridge\Http\HttpHandler;
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

    public function testTheHeadersAreIgnoredOnACustomDomainInTheFunctionRuntime(): void
    {
        $handler = new HttpHandler($this->app->make(Kernel::class));

        $response = $handler->handleRequest(new HttpRequestEvent([
            'version' => '2.0',
            'rawPath' => '/url',
            'headers' => [
                'host' => 'app.example.com',
                'x-forwarded-proto' => 'https',
                'x-forwarded-port' => '443',
                'x-forwarded-host' => 'attacker.example',
                'x-forwarded-prefix' => '/attacker',
            ],
            'requestContext' => [
                'http' => ['method' => 'GET', 'path' => '/url', 'protocol' => 'HTTP/1.1', 'sourceIp' => '203.0.113.10'],
            ],
        ]), Context::fake());

        $this->assertSame('https://app.example.com/path', $response->toApiGatewayFormatV2()['body']);
    }

    public function testTheHeadersAreUsedOnAnAwsDomain(): void
    {
        foreach (['abc123.execute-api.eu-west-1.amazonaws.com', 'abc123.lambda-url.eu-west-1.on.aws'] as $host) {
            $this->assertSame('https://cdn.example.org/prefix/path', $this->url($host));
        }
    }

    public function testTheTrustedProxiesOfTheConfigurationAreKept(): void
    {
        config()->set('trustedproxy.proxies', '*');
        (new BrefServiceProvider($this->app))->register();

        $this->assertSame('*', config('trustedproxy.proxies'));
        $this->assertSame('https://cdn.example.org/prefix/path', $this->url('app.example.com'));
    }

    public function testTheHeadersAreUsedWhenTheMiddlewareTrustsProxies(): void
    {
        $this->app->bind(TrustProxies::class, fn () => new class extends TrustProxies {
            protected $proxies = '*';
        });

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

    public function testChoosingTheTrustedHeadersAloneDoesNotTrustTheHost(): void
    {
        if (! method_exists(TrustProxies::class, 'withHeaders')) {
            $this->markTestSkipped('TrustProxies::withHeaders() was added in Laravel 11');
        }

        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX);

        $this->assertSame('https://app.example.com/path', $this->url('app.example.com'));
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
