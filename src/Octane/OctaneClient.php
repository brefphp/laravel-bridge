<?php

namespace Bref\LaravelBridge\Octane;

use Bref\Event\Http\HttpResponseWriter;
use RuntimeException;
use Throwable;

use Laravel\Octane\Worker;
use Laravel\Octane\RequestContext;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\ApplicationFactory;

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Contracts\Debug\ExceptionHandler;

use Symfony\Component\HttpFoundation\Response;

class OctaneClient implements Client
{
    /**
     * The Octane worker.
     */
    private Worker $worker;

    /**
     * The request being handled, and the writer of its response.
     */
    private Request|null $request = null;
    private HttpResponseWriter|null $writer = null;

    /**
     * The exception thrown while writing the response, which Octane only reports.
     */
    private Throwable|null $responseError = null;

    public function __construct(string $basePath, bool $persistDatabaseSession)
    {
        $this->worker = tap(
            new Worker(new ApplicationFactory($basePath), $this)
        )->boot()->onRequestHandled(
            static::manageDatabaseSessions($persistDatabaseSession)
        );
    }

    /**
     * Handle the given request, writing its response as it is produced (e.g. streamed responses).
     */
    public function handle(Request $request, HttpResponseWriter $writer): void
    {
        $this->worker->application()->useStoragePath('/tmp/storage');

        [$this->request, $this->writer] = [$request, $writer];
        try {
            $this->worker->handle($request, new RequestContext());
        } finally {
            [$this->request, $this->writer] = [null, null];
        }

        // Rethrow it so that Bref reports the failed invocation too
        [$error, $this->responseError] = [$this->responseError, null];
        if ($error) {
            throw $error;
        }
        if (! $writer->started()) {
            throw new RuntimeException('Octane handled the request without responding.');
        }
    }

    /**
     * Write a response: its status and headers, then its content as it is produced (StreamedResponse
     * callbacks, BinaryFileResponse files...).
     *
     * @param string|null $output What the application printed while producing the response.
     */
    public static function send(Response $response, Request $request, HttpResponseWriter $writer, ?string $output = null): void
    {
        if (! $response->headers->has('Content-Type')) {
            $response->prepare($request); // https://github.com/laravel/framework/pull/43895
        }

        $writer->start($response->getStatusCode(), $response->headers->all());
        // Like Octane's other clients: printed before the response's content
        $writer->write((string) $output);
        $writer->capture(fn () => $response->sendContent());
    }

    /**
     * {@inheritdoc}
     */
    public function error(Throwable $exception, Application $app, Request $request, RequestContext $context): void
    {
        // Too late for an error page: the response has started
        if ($this->writer?->started()) {
            return;
        }

        try {
            $response = $app[ExceptionHandler::class]->render($request, $exception);
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage());
            fwrite(STDERR, $exception->getMessage());

            $response = new Response('Internal Server Error', 500);
        }

        $this->respond($context, new OctaneResponse($response));
    }

    /**
     * {@inheritdoc}
     */
    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        try {
            static::send($response->response, $this->request, $this->writer, $response->outputBuffer);
        } catch (Throwable $e) {
            $this->responseError = $e;
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function marshalRequest(RequestContext $context): array
    {
        return [];
    }

    /**
     * Manage the database sessions.
     *
     * @param  bool  $persistDatabaseSession
     * @return callable
     */
    protected static function manageDatabaseSessions(bool $persistDatabaseSession)
    {
        return function ($request, $response, $sandbox) use ($persistDatabaseSession) {
            if ($persistDatabaseSession) {
                return;
            }

            if (! $sandbox->resolved('db')) {
                return;
            }

            collect($sandbox->make('db')->getConnections())->each->disconnect();
        };
    }
}
