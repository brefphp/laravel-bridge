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
    private const API_GATEWAY = 'abc123.execute-api.eu-west-1.amazonaws.com';
    private const FUNCTION_URL = 'abc123.lambda-url.eu-west-1.on.aws';

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

    public function testACustomDomainIsNotOverridden(): void
    {
        $this->assertSame('https://app.example.com/path', $this->url('app.example.com', ['HTTP_X_FORWARDED_HOST' => 'attacker.example']));
    }

    public function testTheHostIsUsedOnTheAwsDomainWithoutApplicationUrl(): void
    {
        $this->assertSame('https://cdn.example.com/path', $this->url(self::API_GATEWAY, ['HTTP_X_FORWARDED_HOST' => 'cdn.example.com']));
        $this->assertSame('https://cdn.example.com/path', $this->url(self::FUNCTION_URL, ['HTTP_X_FORWARDED_HOST' => 'cdn.example.com']));
    }

    #[DataProvider('provideTrustedHosts')]
    public function testTheHostIsUsedWhenItMatchesTheApplicationUrl(string $forwardedHost): void
    {
        config()->set('app.url', 'https://example.com');

        $this->assertSame('https://' . strtolower($forwardedHost) . '/path', $this->url(self::API_GATEWAY, ['HTTP_X_FORWARDED_HOST' => $forwardedHost]));
    }

    public static function provideTrustedHosts(): array
    {
        return [['example.com'], ['EXAMPLE.com'], ['www.example.com']];
    }

    #[DataProvider('provideUntrustedHosts')]
    public function testOtherHostsAreIgnoredWhenTheApplicationUrlIsSet(string $forwardedHost): void
    {
        config()->set('app.url', 'https://example.com');

        $this->assertSame('https://' . self::API_GATEWAY . '/path', $this->url(self::API_GATEWAY, ['HTTP_X_FORWARDED_HOST' => $forwardedHost]));
    }

    public static function provideUntrustedHosts(): array
    {
        return [
            ['attacker.example'],
            ['attackerexample.com'],
            ['example.com.attacker.example'],
            ['attacker.example,www.example.com'],
            ['attacker.example, example.com'],
            ['attacker.example/.example.com'],
        ];
    }

    public function testThePrefixIsIgnored(): void
    {
        $this->assertSame('https://' . self::API_GATEWAY . '/path', $this->url(self::API_GATEWAY, ['HTTP_X_FORWARDED_PREFIX' => '/attacker']));
    }

    public function testTheHeadersCanBeTrusted(): void
    {
        config()->set('app.url', 'https://example.com');
        config()->set('bref.trust_forwarded_host', true);

        $this->assertSame('https://other.example/prefix/path', $this->url('app.example.com', [
            'HTTP_X_FORWARDED_HOST' => 'other.example',
            'HTTP_X_FORWARDED_PREFIX' => '/prefix',
        ]));
    }

    private function url(string $host, array $server): string
    {
        // Under PHP-FPM, Bref sets REMOTE_ADDR to 127.0.0.1
        $request = Request::create("https://$host/url", 'GET', [], [], [], $server + ['REMOTE_ADDR' => '127.0.0.1']);

        return $this->app->make(Kernel::class)->handle($request)->getContent();
    }
}
