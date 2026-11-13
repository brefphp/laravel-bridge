<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Http;

use Bref\Bref;
use Bref\Context\Context;
use Bref\Event\Http\HttpRequestEvent;
use Bref\LaravelBridge\Http\OctaneHandler;
use Bref\LaravelBridge\Octane\OctaneClient;
use Bref\Event\Http\HttpResponse;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
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

        $response = $handler->handleRequest($this->event('/stream'), Context::fake());
        // Nothing of the body is produced before Bref sends it
        $this->assertSame([], $log);

        $chunks = $this->send($response, function () use (&$log) {
            $log[] = 'chunk';
        });

        $metadata = json_decode(substr($chunks[0], 0, -8), true);
        $this->assertSame(201, $metadata['statusCode']);
        $this->assertSame('value', $metadata['headers']['X-Custom']);
        $this->assertSame('Hello world', implode('', array_slice($chunks, 1)));
        // Sent as they are produced, then Laravel finishes the request
        $this->assertSame(['chunk', 'between', 'chunk', 'terminate'], $log);
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
            $this->send($handler->handleRequest($this->event('/stream'), Context::fake()), function (string $chunk) use (&$chunks) {
                $chunks[] = $chunk;
            });
            $this->fail('The exception must reach Bref, to be reported as an invocation error');
        } catch (RuntimeException $e) {
            $this->assertSame('Failure in the middle', $e->getMessage());
        }
        $this->assertSame('Hello', end($chunks));

        // The next request is handled normally
        $this->assertSame('Plain', implode('', array_slice($this->send($handler->handleRequest($this->event('/plain'), Context::fake())), 1)));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAStreamedResponseNeverSentIsFinishedAtTheEndOfTheInvocation(): void
    {
        $log = [];
        $handler = $this->handlerWithRoutes(function (Application $app) use (&$log) {
            $app['router']->get('/stream', function () use (&$log) {
                app()->terminating(function () use (&$log) {
                    $log[] = 'terminate';
                });

                return response()->stream(function () {
                    echo 'Never sent';
                });
            });
            $app['router']->get('/plain', fn () => 'Plain');
        });

        $handler->handleRequest($this->event('/stream'), Context::fake());
        // The invocation ends without sending the response (e.g. it failed before)
        Bref::events()->afterInvoke($handler, [], Context::fake(), null, new RuntimeException('Failure'));
        $this->assertSame(['terminate'], $log);

        $response = $handler->handleRequest($this->event('/plain'), Context::fake());
        $this->assertSame('Plain', implode('', array_slice($this->send($response), 1)));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStreamedResponsesAreBufferedForOtherInvokers(): void
    {
        $handler = $this->handlerWithRoutes(function (Application $app) {
            $app['router']->get('/stream', fn () => response()->stream(function () {
                echo 'Hello';
                flush();
                echo ' world';
            }));
        });

        // API Gateway HTTP API: no streaming
        $result = $handler->handle([
            'version' => '2.0',
            'rawPath' => '/stream',
            'requestContext' => [
                'domainName' => 'abc.execute-api.eu-west-3.amazonaws.com',
                'http' => ['method' => 'GET', 'path' => '/stream', 'protocol' => 'HTTP/1.1', 'sourceIp' => '127.0.0.1'],
            ],
        ], Context::fake());

        $this->assertSame('Hello world', $result['body']);
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

    private function event(string $path): HttpRequestEvent
    {
        return new HttpRequestEvent([
            'version' => '2.0',
            'rawPath' => $path,
            'requestContext' => [
                'http' => ['method' => 'GET', 'path' => $path, 'protocol' => 'HTTP/1.1', 'sourceIp' => '127.0.0.1'],
            ],
        ]);
    }

    /**
     * Sends the response like Bref does to a streamed Function URL.
     *
     * @return string[] The chunks: the status and headers, then the body
     */
    private function send(HttpResponse $response, ?callable $onChunk = null): array
    {
        $chunks = [];
        $response->toStreamedResponse()->writeTo(function (string $chunk) use (&$chunks, $onChunk) {
            $chunks[] = $chunk;
            if ($onChunk && count($chunks) > 1) {
                $onChunk($chunk);
            }
        });

        return $chunks;
    }
}
