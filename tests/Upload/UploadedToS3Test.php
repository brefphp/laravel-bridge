<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Upload;

use Bref\LaravelBridge\Tests\TestCase;
use Bref\LaravelBridge\Upload\UploadedToS3;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UploadedToS3Test extends TestCase
{
    private const UUID = '2c1d4e0a-9a3b-4b6e-8f5a-1c2d3e4f5a6b';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filesystems.default', 's3');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    public function testItAcceptsAFileUploadedByTheCurrentUser(): void
    {
        $this->login(42);
        Storage::put('tmp/42/' . self::UUID . '.pdf', 'content');

        $this->assertNull($this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)));
        $this->assertNull($this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: ['pdf', 'png'])));
    }

    public function testItAcceptsAFileWithoutExtension(): void
    {
        $this->login(42);
        Storage::put('tmp/42/' . self::UUID, 'content');

        $this->assertNull($this->validate('tmp/42/' . self::UUID, new UploadedToS3(extensions: null)));
    }

    public function testItRejectsNonStrings(): void
    {
        $this->login(42);

        $this->assertSame('The file is not a valid upload.', $this->validate(['tmp/42/' . self::UUID], new UploadedToS3(extensions: null)));
        $this->assertSame('The file is not a valid upload.', $this->validate(42, new UploadedToS3(extensions: null)));
    }

    public function testItRejectsKeysOutsideTheUploadPrefix(): void
    {
        $this->login(42);
        Storage::put('documents/42/' . self::UUID . '.pdf', 'content');
        Storage::put('tmp/42/../../documents/secret.pdf', 'content');

        $this->assertSame('The file is not a valid upload.', $this->validate('documents/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)));
        $this->assertSame('The file is not a valid upload.', $this->validate('tmp/42/../../documents/secret.pdf', new UploadedToS3(extensions: null)));
        $this->assertSame('The file is not a valid upload.', $this->validate('/tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)));
    }

    public function testItRejectsAnotherUsersUpload(): void
    {
        $this->login(42);
        Storage::put('tmp/43/' . self::UUID . '.pdf', 'content');

        $this->assertSame('The file is not a valid upload.', $this->validate('tmp/43/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)));
    }

    public function testItRejectsKeysThatAreNotUuids(): void
    {
        $this->login(42);
        Storage::put('tmp/42/report.pdf', 'content');

        $this->assertSame('The file is not a valid upload.', $this->validate('tmp/42/report.pdf', new UploadedToS3(extensions: null)));
    }

    public function testItRejectsExtensionsThatAreNotAllowed(): void
    {
        $this->login(42);
        Storage::put('tmp/42/' . self::UUID . '.exe', 'content');
        Storage::put('tmp/42/' . self::UUID, 'content');

        $rule = new UploadedToS3(extensions: ['pdf', 'png']);
        $this->assertSame('The file is not a valid upload.', $this->validate('tmp/42/' . self::UUID . '.exe', $rule));
        // Without extension
        $this->assertSame('The file is not a valid upload.', $this->validate('tmp/42/' . self::UUID, $rule));
    }

    public function testItRejectsMissingFiles(): void
    {
        $this->login(42);

        $this->assertSame(
            'The file was not found. Please upload it again.',
            $this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)),
        );
    }

    public function testItRejectsFilesLargerThanTheMaximumSize(): void
    {
        $this->login(42);
        Storage::put('tmp/42/' . self::UUID . '.pdf', str_repeat('a', 2048));

        $this->assertSame(
            'The file exceeds the maximum size of 1 KB.',
            $this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null, maxSize: 1024)),
        );
        $this->assertNull($this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null, maxSize: 2048)));
    }

    public function testTheMaximumSizeDefaultsToTheConfiguration(): void
    {
        config()->set('bref.uploads.max_size', 5 * 1024 * 1024);
        $this->login(42);
        Storage::put('tmp/42/' . self::UUID . '.pdf', str_repeat('a', 5 * 1024 * 1024 + 1));

        $this->assertSame(
            'The file exceeds the maximum size of 5 MB.',
            $this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)),
        );
        // An explicit limit takes precedence over the configuration
        $this->assertNull($this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null, maxSize: 6 * 1024 * 1024)));
    }

    public function testMessagesCanBeTranslated(): void
    {
        $langPath = sys_get_temp_dir() . '/bref-upload-lang-' . uniqid();
        mkdir($langPath);
        file_put_contents($langPath . '/fr.json', json_encode([
            'The :attribute is not a valid upload.' => 'Le fichier :attribute est invalide.',
            'The :attribute exceeds the maximum size of :max.' => 'Le fichier :attribute dépasse :max.',
        ]));
        $this->app['translator']->addJsonPath($langPath);
        $this->app->setLocale('fr');
        $this->login(42);
        Storage::put('tmp/42/' . self::UUID . '.pdf', str_repeat('a', 2048));

        $this->assertSame('Le fichier file est invalide.', $this->validate('tmp/43/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)));
        $this->assertSame('Le fichier file dépasse 1 KB.', $this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null, maxSize: 1024)));
    }

    public function testGuestsCanOnlyReferenceGuestUploads(): void
    {
        Storage::put('tmp/' . self::UUID . '.pdf', 'content');
        Storage::put('tmp/42/' . self::UUID . '.pdf', 'content');

        $this->assertNull($this->validate('tmp/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)));
        $this->assertSame('The file is not a valid upload.', $this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)));
    }

    public function testMissingSizeConfigurationKeepsTheDefaultButNullDisablesIt(): void
    {
        $this->login(42);
        $key = 'tmp/42/' . self::UUID . '.pdf';
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('exists')->with($key)->twice()->andReturn(true);
        $disk->shouldReceive('size')->with($key)->once()->andReturn(50 * 1024 * 1024 + 1);
        Storage::shouldReceive('disk')->with('s3')->twice()->andReturn($disk);

        config()->set('bref.uploads', ['route' => '/uploads/sign']);
        $this->assertSame('The file exceeds the maximum size of 50 MB.', $this->validate($key, new UploadedToS3(extensions: null)));

        config()->set('bref.uploads.max_size', null);
        $this->assertNull($this->validate($key, new UploadedToS3(extensions: null)));
    }

    public function testUsersCannotReferenceGuestUploads(): void
    {
        $this->login(42);
        Storage::put('tmp/' . self::UUID . '.pdf', 'content');

        $this->assertSame('The file is not a valid upload.', $this->validate('tmp/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)));
    }

    public function testItUsesACustomPrefix(): void
    {
        config()->set('bref.uploads.prefix', 'uploads/temporary');
        $this->login(42);
        Storage::put('uploads/temporary/42/' . self::UUID . '.pdf', 'content');

        $this->assertNull($this->validate('uploads/temporary/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)));
        $this->assertSame('The file is not a valid upload.', $this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)));
    }

    public function testItChecksTheGivenDisk(): void
    {
        Storage::fake('other');
        $this->login(42);
        Storage::disk('other')->put('tmp/42/' . self::UUID . '.pdf', 'content');

        $this->assertNull($this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null, disk: 'other')));
        $this->assertSame(
            'The file was not found. Please upload it again.',
            $this->validate('tmp/42/' . self::UUID . '.pdf', new UploadedToS3(extensions: null)),
        );
    }

    private function login(int $id): void
    {
        $user = new User;
        $user->id = $id;
        $this->actingAs($user);
    }

    /**
     * @return string|null The first error message, or null when validation passes.
     */
    private function validate(mixed $value, UploadedToS3 $rule): ?string
    {
        $validator = Validator::make(['file' => $value], ['file' => [$rule]]);

        return $validator->errors()->first('file') ?: null;
    }
}
