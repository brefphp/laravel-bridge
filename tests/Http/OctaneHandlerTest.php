<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Http;

use Bref\Context\Context;
use Bref\Event\Http\HttpRequestEvent;
use Bref\LaravelBridge\Http\OctaneHandler;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Orchestra\Testbench\TestCase as Testbench;
use PHPUnit\Framework\TestCase;

class OctaneHandlerTest extends TestCase
{
    private int $outputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputBufferLevel = ob_get_level();
        // The Testbench skeleton the handler boots has no .env, and Octane warms the encrypter
        $_SERVER['APP_KEY'] = 'base64:' . base64_encode(random_bytes(32));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_KEY']);
        // The application booted by the handler registers its error handlers and output buffers.
        // flushState() exists since Laravel 11; older versions run on PHPUnit versions that do not check the handlers.
        if (method_exists(HandleExceptions::class, 'flushState')) {
            HandleExceptions::flushState($this);
        }
        while (ob_get_level() > $this->outputBufferLevel) {
            ob_end_clean();
        }

        parent::tearDown();
    }

    /**
     * The handler boots a whole Laravel application, whose static state (Artisan bootstrappers, facades) would leak
     * into the next tests.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFilesUploadedWithAPreviousRequestAreDeleted(): void
    {
        // Named like Bref names uploads on Lambda: tempnam() adds 6 characters on Linux
        $previousUpload = sys_get_temp_dir() . '/bref_upload_' . bin2hex(random_bytes(3));
        touch($previousUpload);

        $handler = new OctaneHandler(Testbench::applicationBasePath());
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
