<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Http;

use Bref\Context\Context;
use Bref\Event\Http\HttpRequestEvent;
use Bref\LaravelBridge\Http\HttpHandler;
use Bref\LaravelBridge\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;

class HttpHandlerTest extends TestCase
{
    public function testFilesUploadedWithAPreviousRequestAreDeleted(): void
    {
        // Named like Bref names uploads
        $previousUpload = sys_get_temp_dir() . '/bref_upload_' . bin2hex(random_bytes(3));
        touch($previousUpload);

        $handler = new HttpHandler($this->app->make(Kernel::class));
        $handler->handleRequest(new HttpRequestEvent([
            'version' => '2.0',
            'rawPath' => '/',
            'requestContext' => [
                'http' => ['method' => 'GET', 'path' => '/', 'protocol' => 'HTTP/1.1', 'sourceIp' => '127.0.0.1'],
            ],
        ]), Context::fake());

        $this->assertFileDoesNotExist($previousUpload);
    }
}
