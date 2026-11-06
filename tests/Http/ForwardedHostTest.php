<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Http;

use Bref\LaravelBridge\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class ForwardedHostTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['LAMBDA_TASK_ROOT'] = __DIR__;

        parent::setUp();

        Route::get('/url', fn () => url('/path'));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LAMBDA_TASK_ROOT']);

        parent::tearDown();
    }

    public function testTheHeadersAreIgnoredOnACustomDomain(): void
    {
        $this->assertSame('https://app.example.com/path', $this->url('app.example.com'));
    }

    #[DataProvider('provideDomainsThatLookLikeAws')]
    public function testTheHeadersAreIgnoredOnADomainThatOnlyLooksLikeAws(string $host): void
    {
        $this->assertSame("https://$host/path", $this->url($host));
    }

    public static function provideDomainsThatLookLikeAws(): array
    {
        return [['amazonaws.com.example.com'], ['fakeamazonaws.com'], ['example.on.aws.example.com']];
    }

    #[DataProvider('provideAwsDomains')]
    public function testTheHeadersAreUsedOnAnAwsDomain(string $host): void
    {
        // Not related to APP_URL: an application can be served on several domains
        config()->set('app.url', 'https://example.com');

        $this->assertSame('https://cdn.example.org/prefix/path', $this->url($host));
    }

    public static function provideAwsDomains(): array
    {
        return [['abc123.execute-api.eu-west-1.amazonaws.com'], ['abc123.lambda-url.eu-west-1.on.aws']];
    }

    public function testTheHeadersCanBeTrustedOnACustomDomain(): void
    {
        config()->set('bref.trust_forwarded_host', true);

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
