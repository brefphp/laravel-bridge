<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Upload;

use Bref\LaravelBridge\Tests\TestCase;
use Bref\LaravelBridge\Upload\Uploads;
use Illuminate\Foundation\Auth\User;

class UploadsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filesystems.default', 's3');
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
