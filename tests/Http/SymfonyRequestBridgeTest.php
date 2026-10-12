<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Http;

use Bref\Context\Context;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\Http\Psr7Bridge;
use Bref\LaravelBridge\Http\SymfonyRequestBridge;
use PHPUnit\Framework\TestCase;

class SymfonyRequestBridgeTest extends TestCase
{
    public function testUploadedFilesAreDeletedByTheNextRequestCleanup(): void
    {
        $body = "--boundary\r\n"
            . "Content-Disposition: form-data; name=\"document\"; filename=\"document.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . "Hello\r\n"
            . "--boundary--\r\n";
        $request = SymfonyRequestBridge::convertRequest(new HttpRequestEvent([
            'version' => '2.0',
            'rawPath' => '/',
            'headers' => ['content-type' => 'multipart/form-data; boundary=boundary'],
            'body' => $body,
            'isBase64Encoded' => false,
            'requestContext' => [
                'http' => ['method' => 'POST', 'path' => '/', 'protocol' => 'HTTP/1.1', 'sourceIp' => '127.0.0.1'],
            ],
        ]), Context::fake());

        // Symfony moves the file Bref created to a new temporary file
        $path = $request->files->get('document')->getPathname();
        $this->assertSame('Hello', file_get_contents($path));

        Psr7Bridge::cleanupUploadedFiles();

        $this->assertFileDoesNotExist($path);
    }
}
