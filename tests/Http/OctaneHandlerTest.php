<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Http;

use Bref\Context\Context;
use Bref\LaravelBridge\Http\OctaneHandler;
use Illuminate\Foundation\Application;
use Laravel\Octane\OctaneServiceProvider;
use Laravel\Octane\Worker;
use RuntimeException;
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
        putenv('BREF_RESPONSE_STREAMING');
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
        // Named like Bref names uploads
        $previousUpload = sys_get_temp_dir() . '/bref_upload_' . bin2hex(random_bytes(3));
        touch($previousUpload);

        $this->handlerWithRoutes(fn () => null)->handle($this->event('/', 'abc.execute-api.eu-west-3.amazonaws.com'), Context::fake())->contents();

        $this->assertFileDoesNotExist($previousUpload);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStreamedResponsesAreSentWhileTheirCallbackRuns(): void
    {
        $log = [];
        $handler = $this->handlerWithRoutes(function (Application $app) use (&$log) {
            $app['router']->get('/stream', function () use (&$log) {
                app()->terminating(function () use (&$log) {
                    $log[] = 'terminate';
                });

                return response()->stream(function () use (&$log) {
                    echo 'Hello';
                    flush();
                    $log[] = 'between';
                    echo ' world';
                }, 201, ['X-Custom' => 'value']);
            });
        });

        $chunks = $this->stream($handler, '/stream', function () use (&$log) {
            $log[] = 'chunk';
        }, function () use (&$log) {
            $log[] = 'end';
        });

        $metadata = json_decode(substr($chunks[0], 0, -8), true);
        $this->assertSame(201, $metadata['statusCode']);
        $this->assertSame('value', $metadata['headers']['X-Custom']);
        $this->assertSame('Hello world', implode('', array_slice($chunks, 1)));
        // Sent as they are produced, then the client gets the end of the response, then Laravel finishes the request
        $this->assertSame(['chunk', 'between', 'chunk', 'end', 'terminate'], $log);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExceptionsInStreamedResponsesAreThrownToBref(): void
    {
        $handler = $this->handlerWithRoutes(function (Application $app) {
            $app['router']->get('/stream', fn () => response()->stream(function () {
                echo 'Hello';
                throw new RuntimeException('Failure in the middle');
            }));
            $app['router']->get('/plain', fn () => 'Plain');
        });

        $chunks = [];
        try {
            $this->stream($handler, '/stream', function (string $chunk) use (&$chunks) {
                $chunks[] = $chunk;
            });
            $this->fail('The exception must reach Bref, to be reported as an invocation error');
        } catch (RuntimeException $e) {
            $this->assertSame('Failure in the middle', $e->getMessage());
        }
        // Too late for an error page
        $this->assertSame(['Hello'], $chunks);

        // The next request is handled normally
        $this->assertSame('Plain', implode('', array_slice($this->stream($handler, '/plain'), 1)));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExceptionsBeforeTheResponseGiveTheErrorPage(): void
    {
        $handler = $this->handlerWithRoutes(function (Application $app) {
            $app['router']->get('/error', fn () => throw new RuntimeException('Failure'));
        });

        $chunks = $this->stream($handler, '/error');

        $this->assertSame(500, json_decode(substr($chunks[0], 0, -8), true)['statusCode']);
        $this->assertStringContainsString('Server Error', implode('', array_slice($chunks, 1)));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFilesAreSentInChunks(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bref-');
        file_put_contents($file, str_repeat('a', 100_000));
        $handler = $this->handlerWithRoutes(function (Application $app) use ($file) {
            $app['router']->get('/download', fn () => response()->download($file));
        });

        $chunks = $this->stream($handler, '/download');
        unlink($file);

        $this->assertGreaterThan(2, count($chunks));
        $this->assertSame(str_repeat('a', 100_000), implode('', array_slice($chunks, 1)));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResponsesAreBufferedForOtherInvokers(): void
    {
        $handler = $this->handlerWithRoutes(function (Application $app) {
            $app['router']->get('/stream', fn () => response()->stream(function () {
                echo 'Hello';
                flush();
                echo ' world';
            }));
        });

        // API Gateway HTTP API: no streaming
        $body = '';
        $handler->handle($this->event('/stream', 'abc.execute-api.eu-west-3.amazonaws.com'), Context::fake())->writeTo(function (string $chunk) use (&$body) {
            $body .= $chunk;
        });

        $this->assertSame('Hello world', json_decode($body, true)['body']);
    }

    /**
     * @param callable(Application): void $routes
     */
    private function handlerWithRoutes(callable $routes): OctaneHandler
    {
        $handler = new OctaneHandler(Testbench::applicationBasePath());
        // The application Octane clones for each request
        $client = (fn () => $this->octaneClient)->call($handler);
        $worker = (fn (): Worker => $this->worker)->call($client);
        // A real application registers it (package discovery), the Testbench skeleton does not
        $worker->application()->register(OctaneServiceProvider::class);
        $routes($worker->application());

        return $handler;
    }

    private function event(string $path, string $domain): array
    {
        return [
            'version' => '2.0',
            'rawPath' => $path,
            'requestContext' => [
                'domainName' => $domain,
                'http' => ['method' => 'GET', 'path' => $path, 'protocol' => 'HTTP/1.1', 'sourceIp' => '127.0.0.1'],
            ],
        ];
    }

    /**
     * Handles the request like Bref does for a streamed Function URL.
     *
     * @return string[] The chunks: the status and headers, then the body
     */
    private function stream(OctaneHandler $handler, string $path, ?callable $onBodyChunk = null, ?callable $onEnd = null): array
    {
        putenv('BREF_RESPONSE_STREAMING=1');
        $chunks = [];
        $handler->handle($this->event($path, 'abc.lambda-url.eu-west-3.on.aws'), Context::fake())->writeTo(function (string $chunk) use (&$chunks, $onBodyChunk) {
            $chunks[] = $chunk;
            if ($onBodyChunk && count($chunks) > 1) {
                $onBodyChunk($chunk);
            }
        }, $onEnd);

        return $chunks;
    }
}
