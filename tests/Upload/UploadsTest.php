<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Upload;

use Bref\LaravelBridge\Tests\TestCase;
use Bref\LaravelBridge\Upload\Uploads;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class UploadsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filesystems.default', 's3');
    }

    public function testMoveCopiesThenDeletesTheTemporaryFile(): void
    {
        Storage::fake('s3');
        Storage::put('tmp/42/upload.pdf', 'content');

        Uploads::move('tmp/42/upload.pdf', 'documents/report.pdf');

        Storage::assertExists('documents/report.pdf');
        $this->assertSame('content', Storage::get('documents/report.pdf'));
        Storage::assertMissing('tmp/42/upload.pdf');
    }

    public function testMoveUsesTheGivenDisk(): void
    {
        Storage::fake('s3');
        Storage::fake('other');
        Storage::disk('other')->put('tmp/42/upload.pdf', 'content');

        Uploads::move('tmp/42/upload.pdf', 'documents/report.pdf', 'other');

        Storage::disk('other')->assertExists('documents/report.pdf');
        Storage::disk('other')->assertMissing('tmp/42/upload.pdf');
        Storage::disk('s3')->assertMissing('documents/report.pdf');
    }

    public function testMoveThrowsWhenTheFileDoesNotExist(): void
    {
        Storage::fake('s3');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not move the upload "tmp/42/missing.pdf" to "documents/report.pdf".');

        Uploads::move('tmp/42/missing.pdf', 'documents/report.pdf');
    }

    public function testMoveReportsButDoesNotThrowWhenTheTemporaryFileCannotBeDeleted(): void
    {
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('copy')->with('tmp/42/upload.pdf', 'documents/report.pdf')->once()->andReturn(true);
        $disk->shouldReceive('delete')->with('tmp/42/upload.pdf')->once()->andThrow(new RuntimeException('Access denied'));
        Storage::shouldReceive('disk')->with('s3')->once()->andReturn($disk);
        $handler = \Mockery::mock(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $handler->shouldReceive('report')->once();
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);

        Uploads::move('tmp/42/upload.pdf', 'documents/report.pdf');
    }

    public function testDirectory(): void
    {
        $user = new User;
        $user->id = 42;

        $this->assertSame('tmp/42', Uploads::directory($user));

        config()->set('bref.uploads.prefix', '/uploads/');
        $this->assertSame('uploads/42', Uploads::directory($user));
    }

    public function testDirectoryForGuests(): void
    {
        $this->assertSame('tmp', Uploads::directory(null));
    }

    public function testDisk(): void
    {
        $this->assertSame('s3', Uploads::disk());

        config()->set('bref.uploads.disk', 'uploads');
        $this->assertSame('uploads', Uploads::disk());
    }
}
