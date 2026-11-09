<?php

namespace Bref\LaravelBridge\Octane;

use Fiber;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

class OctaneClient implements Client
{
    /**
     * The Octane worker.
     */
    private Worker $worker;

    /**
     * The response of the last request that was processed.
     */
    private OctaneResponse|null $response;

    public function __construct(string $basePath, bool $persistDatabaseSession)
    {
        $this->worker = tap(
            new Worker(new ApplicationFactory($basePath), $this)
        )->boot()->onRequestHandled(
            static::manageDatabaseSessions($persistDatabaseSession)
        );
    }

    /**
     * Handle the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request): Response
    {
        $this->worker->application()->useStoragePath('/tmp/storage');

        $this->worker->handle($request, new RequestContext);

        $response = clone $this->response->response;
        $this->response = null;

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function error(Throwable $exception, Application $app, Request $request, RequestContext $context): void
    {
        try {
            $this->response = new OctaneResponse(
                $app[ExceptionHandler::class]->render($request, $exception)
            );
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage());
            fwrite(STDERR, $exception->getMessage());

            $this->response = new OctaneResponse(
                new Response('Internal Server Error', 500)
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        // Streamed responses are sent while their callback runs, inside the request (see OctaneHandler)
        if ($response->response instanceof StreamedResponse && Fiber::getCurrent() !== null) {
            // Hand the status and headers over, then each chunk of output
            Fiber::suspend($response->response);
            ob_start(function (string $buffer): string {
                if ($buffer === '' || Fiber::getCurrent() === null) {
                    return $buffer;
                }
                Fiber::suspend($buffer);

                return '';
            }, 1);
            try {
                $response->response->sendContent();
            } finally {
                ob_end_clean();
            }
        }

        $this->response = $response;
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
