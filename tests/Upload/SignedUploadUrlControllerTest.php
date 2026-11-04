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

    protected function customMaxSize($app): void
    {
        $app['config']->set('bref.uploads.max_size', 1024);
    }

    protected function noMaxSize($app): void
    {
        $app['config']->set('bref.uploads.max_size', null);
    }

    protected function diskWithRoot($app): void
    {
        $app['config']->set('bref.uploads.disk', 's3-with-root');
        $app['config']->set('filesystems.disks.s3-with-root', [
            'driver' => 's3',
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'eu-west-1',
            'bucket' => 'my-bucket',
            'root' => 'app',
        ]);
    }

    protected function localDisk($app): void
    {
        $app['config']->set('bref.uploads.disk', 'local');
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

    public function testItReturnsAPresignedPostForm(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $response = $this->actingAs($this->user())
            ->postJson(self::URL, ['content_type' => 'application/pdf'])
            ->assertCreated()
            ->assertJsonStructure(['uuid', 'key', 'bucket', 'url', 'fields', 'extension']);

        $uuid = $response->json('uuid');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $uuid);
        $this->assertSame("tmp/42/$uuid.pdf", $response->json('key'));
        $this->assertSame('my-bucket', $response->json('bucket'));
        $this->assertSame('pdf', $response->json('extension'));
        $this->assertSame('https://my-bucket.s3.eu-west-1.amazonaws.com', $response->json('url'));

        $fields = $response->json('fields');
        $this->assertSame("tmp/42/$uuid.pdf", $fields['key']);
        $this->assertSame('application/pdf', $fields['Content-Type']);
        $this->assertArrayHasKey('X-Amz-Signature', $fields);

        $policy = json_decode(base64_decode($fields['Policy']), true);
        $this->assertContains(['bucket' => 'my-bucket'], $policy['conditions']);
        $this->assertContains(['key' => "tmp/42/$uuid.pdf"], $policy['conditions']);
        $this->assertContains(['Content-Type' => 'application/pdf'], $policy['conditions']);
        $this->assertContains(['content-length-range', 0, 50 * 1024 * 1024], $policy['conditions']);
        $expiration = strtotime($policy['expiration']) - time();
        $this->assertGreaterThan(290, $expiration);
        $this->assertLessThanOrEqual(300, $expiration);
    }

    #[DefineEnvironment('customMaxSize')]
    public function testS3EnforcesTheConfiguredMaximumSize(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $response = $this->actingAs($this->user())->postJson(self::URL)->assertCreated();

        $policy = json_decode(base64_decode($response->json('fields.Policy')), true);
        $this->assertContains(['content-length-range', 0, 1024], $policy['conditions']);
    }

    #[DefineEnvironment('noMaxSize')]
    public function testTheSizeIsNotLimitedWithoutMaximumSize(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $response = $this->actingAs($this->user())->postJson(self::URL)->assertCreated();

        $policy = json_decode(base64_decode($response->json('fields.Policy')), true);
        foreach ($policy['conditions'] as $condition) {
            $this->assertNotSame('content-length-range', $condition[0] ?? null);
        }
    }

    #[DefineEnvironment('diskWithRoot')]
    public function testTheS3KeyIncludesTheRootOfTheDisk(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $response = $this->actingAs($this->user())->postJson(self::URL)->assertCreated();

        // The application references the key relative to the disk
        $this->assertSame('tmp/42/' . $response->json('uuid'), $response->json('key'));
        $this->assertSame('app/tmp/42/' . $response->json('uuid'), $response->json('fields.key'));
    }

    #[DefineEnvironment('localDisk')]
    public function testTheUploadDiskMustBeAnS3Disk(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $this->withoutExceptionHandling();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The `local` disk used for uploads must be an S3 disk');

        $this->actingAs($this->user())->postJson(self::URL);
    }

    public function testItDefaultsToOctetStreamWithoutExtension(): void
    {
        Gate::define('uploadFiles', fn (User $user) => true);

        $response = $this->actingAs($this->user())
            ->postJson(self::URL)
            ->assertCreated();

        $this->assertNull($response->json('extension'));
        $this->assertSame('tmp/42/' . $response->json('uuid'), $response->json('key'));
        $this->assertSame('application/octet-stream', $response->json('fields.Content-Type'));
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
