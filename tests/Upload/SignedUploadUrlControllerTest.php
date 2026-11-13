<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Upload;

use Bref\LaravelBridge\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class SignedUploadUrlControllerTest extends TestCase
{
    private const URL = '/signed-upload-url';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Required by the `web` middleware group (encrypted cookies)
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // The S3 adapter presigns URLs locally: no network call is made
        $app['config']->set('filesystems.default', 's3');
        $app['config']->set('filesystems.disks.s3', [
            'driver' => 's3',
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'eu-west-1',
            'bucket' => 'my-bucket',
        ]);
    }

    protected function disableRoute($app): void
    {
        $app['config']->set('bref.uploads.route', null);
    }

    protected function allowGuests($app): void
    {
        $app['config']->set('bref.uploads.middleware', ['web']);
    }

    protected function partialUploadConfig($app): void
    {
        $app['config']->set('bref.uploads', ['max_size' => 1024]);
    }

    protected function throttleUploads($app): void
    {
        $app['config']->set('bref.uploads.throttle', '1,1');
        $app['config']->set('cache.default', 'array');
    }

    protected function useNamedUploadLimiter($app): void
    {
        $app['config']->set('bref.uploads.throttle', 'signed-uploads');
        $app['config']->set('cache.default', 'array');
    }

    #[DefineEnvironment('throttleUploads')]
    public function testSigningRequestsCanBeRateLimited(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);
        $this->actingAs($this->user());

        $this->postJson(self::URL)->assertCreated();
        $this->postJson(self::URL)->assertStatus(429);
    }

    #[DefineEnvironment('useNamedUploadLimiter')]
    public function testSigningRequestsCanUseANamedRateLimiter(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('signed-uploads', fn () => \Illuminate\Cache\RateLimiting\Limit::perMinute(1));
        Gate::define('uploadFiles', fn (User $user) => true);
        $this->actingAs($this->user());

        $this->postJson(self::URL)->assertCreated();
        $this->postJson(self::URL)->assertStatus(429);
    }

    public function testSigningRequestsAreNotThrottledByDefault(): void
    {
        $this->assertSame(['web', 'auth'], app('router')->getRoutes()->getByName('bref.uploads.signed-url')->middleware());
    }

    #[DefineEnvironment('partialUploadConfig')]
    public function testPartialConfigurationKeepsTheDefaultRouteAndAuthentication(): void
    {
        Gate::define('uploadFiles', fn (?User $user) => true);

        $this->postJson(self::URL)->assertUnauthorized();
        $this->assertSame(['web', 'auth'], app('router')->getRoutes()->getByName('bref.uploads.signed-url')->middleware());
    }

    public function testTheUploadGateDoesNotReceiveATargetUser(): void
    {
        Gate::define('uploadFiles', function (User $user, ...$arguments) {
            $this->assertSame([], $arguments);
            return true;
        });

        $this->actingAs($this->user())->postJson(self::URL)->assertCreated();
    }

    public function testParameterizedContentTypesKeepTheirExtension(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $this->actingAs($this->user())
            ->postJson(self::URL, ['content_type' => 'text/plain; charset=utf-8'])
            ->assertCreated()
            ->assertJsonPath('extension', 'txt');
    }

    public function testContentTypesCannotContainHeaderLineBreaks(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $this->actingAs($this->user())
            ->postJson(self::URL, ['content_type' => "text/plain\r\nX-Test: injected"])
            ->assertUnprocessable();
    }

    public function testContentTypeHeadersAreMergedCaseInsensitively(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('temporaryUploadUrl')->once()->andReturn([
            'url' => 'https://example.com/upload',
            'headers' => ['content-type' => ['application/pdf'], 'Host' => ['example.com']],
        ]);
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->with('s3')->once()->andReturn($disk);

        $response = $this->actingAs($this->user())
            ->postJson(self::URL, ['content_type' => 'application/pdf'])->assertCreated();

        $this->assertSame(['Content-Type' => 'application/pdf'], $response->json('headers'));
    }

    public function testItDeniesTheRequestWhenTheGateIsNotDefined(): void
    {
        $this->actingAs($this->user())
            ->postJson(self::URL, ['content_type' => 'application/pdf'])
            ->assertForbidden();
    }

    public function testItDeniesTheRequestWhenTheGateReturnsFalse(): void
    {
        Gate::define('uploadFiles', fn (User $user) => false);

        $this->actingAs($this->user())
            ->postJson(self::URL, ['content_type' => 'application/pdf'])
            ->assertForbidden();
    }

    public function testItRequiresAuthenticationByDefault(): void
    {
        Gate::define('uploadFiles', fn (?User $user) => true);

        $this->postJson(self::URL, ['content_type' => 'application/pdf'])
            ->assertUnauthorized();
    }

    #[DefineEnvironment('allowGuests')]
    public function testItDeniesGuestsUnlessTheGateAcceptsANullUser(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $this->postJson(self::URL, ['content_type' => 'application/pdf'])
            ->assertForbidden();
    }

    #[DefineEnvironment('allowGuests')]
    public function testItDoesNotPrefixTheKeyWithAUserIdForGuests(): void
    {
        Gate::define('uploadFiles', fn (?User $user) => true);

        $response = $this->postJson(self::URL, ['content_type' => 'image/jpeg'])
            ->assertCreated();

        $this->assertSame('tmp/' . $response->json('uuid') . '.jpg', $response->json('key'));
    }

    public function testItReturnsAPresignedUploadUrl(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $response = $this->actingAs($this->user())
            ->postJson(self::URL, ['content_type' => 'application/pdf'])
            ->assertCreated()
            ->assertJsonStructure(['uuid', 'key', 'bucket', 'url', 'headers', 'extension']);

        $uuid = $response->json('uuid');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $uuid);
        $this->assertSame("tmp/42/$uuid.pdf", $response->json('key'));
        $this->assertSame('my-bucket', $response->json('bucket'));
        $this->assertSame('pdf', $response->json('extension'));

        $url = $response->json('url');
        $this->assertStringStartsWith('https://my-bucket.s3.eu-west-1.amazonaws.com/tmp/42/', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        $this->assertStringContainsString('X-Amz-Expires=300', $url);

        $headers = $response->json('headers');
        $this->assertSame('application/pdf', $headers['Content-Type']);
        foreach (array_keys($headers) as $name) {
            $this->assertNotSame('host', strtolower($name), 'The Host header must not be returned');
            $this->assertIsString($headers[$name], 'Headers must be flattened to strings');
        }
    }

    public function testItDefaultsToOctetStreamWithoutExtension(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $response = $this->actingAs($this->user())
            ->postJson(self::URL)
            ->assertCreated();

        $this->assertNull($response->json('extension'));
        $this->assertSame('tmp/42/' . $response->json('uuid'), $response->json('key'));
        $this->assertSame('application/octet-stream', $response->json('headers.Content-Type'));
    }

    public function testItRejectsInvalidContentType(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $this->actingAs($this->user())
            ->postJson(self::URL, ['content_type' => ['not', 'a', 'string']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content_type');

        $this->actingAs($this->user())
            ->postJson(self::URL, ['content_type' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content_type');
    }

    public function testTheRouteIsNamed(): void
    {
        $this->assertSame('http://localhost' . self::URL, route('bref.uploads.signed-url'));
    }

    #[DefineEnvironment('disableRoute')]
    public function testTheRouteIsNotRegisteredWhenDisabled(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $this->actingAs($this->user())
            ->postJson(self::URL, ['content_type' => 'application/pdf'])
            ->assertNotFound();
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 42;

        return $user;
    }
}
