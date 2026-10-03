<?php

namespace Bref\LaravelBridge\Octane;

use Bref\Bref;
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
     * The fiber handling the current request, only set when running in
     * streaming mode with fiber support.
     */
    protected \Fiber|null $handleCurrentFiber = null;

    /**
     * Whether the current fiber already suspended with its response.
     */
    protected bool $currentFiberHasResponded = false;

    /**
     * The response of the last request that was processed, only set when not
     * running in streaming mode with fiber support.
     */
    private OctaneResponse|null $response;

    public function __construct(string $basePath, bool $persistDatabaseSession)
    {
        $this->worker = tap(
            new Worker(new ApplicationFactory($basePath), $this)
        )->boot()->onRequestHandled(
            static::manageDatabaseSessions($persistDatabaseSession)
        );

        Bref::events()->subscribe(new FiberTerminationSubscriber($this));
    }

    /**
     * Handle the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request): Response
    {
        if ($this->streamingWithFibers()) {
            return $this->handleFiberableRequest($request);
        }

        $this->worker->application()->useStoragePath('/tmp/storage');

        $this->worker->handle($request, new RequestContext());

        if (! $this->response instanceof OctaneResponse) {
            throw new RuntimeException('Octane handled the request without responding.');
        }

        $response = clone $this->response->response;
        $this->response = null;

        return $response;
    }

    /**
     * Terminate the previous request's fiber so that Octane finishes its
     * lifecycle (request handled callbacks, termination, sandbox flush) once
     * the streamed response has been sent.
     */
    public function ensureExistingFiberIsTerminated(): void
    {
        $fiber = $this->handleCurrentFiber;
        $this->handleCurrentFiber = null;
        $this->currentFiberHasResponded = false;

        if (! $fiber instanceof \Fiber || ! $fiber->isStarted()) {
            return;
        }

        while ($fiber->isSuspended()) {
            $fiber->resume();
        }
    }

    /**
     * Run the request in a fiber so that Octane's lifecycle stays alive until
     * the response body has been streamed.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleFiberableRequest(Request $request): Response
    {
        $this->ensureExistingFiberIsTerminated();

        $this->currentFiberHasResponded = false;
        $this->handleCurrentFiber = new \Fiber(
            function () use ($request) {
                $this->worker->application()->useStoragePath('/tmp/storage');

                $this->worker->handle($request, new RequestContext());
            }
        );

        try {
            $octaneResponse = $this->handleCurrentFiber->start();

            if (! $octaneResponse instanceof OctaneResponse) {
                throw new RuntimeException('Octane handled the request without responding.');
            }

            return $octaneResponse->response;
        } finally {
            // On failure there is nothing left to resume: allow the fiber to
            // be cleaned up. On success it stays suspended until the response
            // has been streamed (@see ensureExistingFiberIsTerminated()).
            if ($this->handleCurrentFiber->isTerminated()) {
                $this->handleCurrentFiber = null;
            }
        }
    }

    /**
     * Whether we are running in streaming mode with fiber support.
     *
     * Without fibers, Octane's lifecycle (e.g. database sessions) finishes
     * before the body is streamed, which breaks responses that lazily query
     * the database. `BREF_STREAM_NO_FIBER` opts out of fibers.
     */
    protected function streamingWithFibers(): bool
    {
        if (! $this->isRunningInStreamingMode()) {
            return false;
        }

        return ! (bool) getenv('BREF_STREAM_NO_FIBER');
    }

    /**
     * Whether Bref is running in streamed response mode.
     *
     * This mirrors `Bref::isRunningInStreamingMode()` (see bref/bref).
     */
    private function isRunningInStreamingMode(): bool
    {
        return (bool) getenv('BREF_STREAMED_MODE');
    }

    /**
     * {@inheritdoc}
     */
    public function error(Throwable $exception, Application $app, Request $request, RequestContext $context): void
    {
        try {
            $octaneResponse = new OctaneResponse(
                $app[ExceptionHandler::class]->render($request, $exception)
            );
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage());
            fwrite(STDERR, $exception->getMessage());

            $octaneResponse = new OctaneResponse(
                new Response('Internal Server Error', 500)
            );
        }

        if ($this->streamingWithFibers() && $this->currentFiberHasResponded) {
            fwrite(STDERR, 'Request failed and already started sending: ' . $exception->getMessage());
            return;
        }

        $this->handOffResponse($octaneResponse);
    }

    /**
     * {@inheritdoc}
     */
    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        $this->handOffResponse($response);
    }

    /**
     * Send the Octane response to the client.
     *
     * In streaming mode the fiber suspends so that the response body is
     * streamed before Octane finishes the request lifecycle; it is resumed
     * once the invocation has ended (@see FiberTerminationSubscriber).
     */
    protected function handOffResponse(OctaneResponse $octaneResponse): void
    {
        if ($this->streamingWithFibers()) {
            // Only suspend when running inside the request fiber: Octane may
            // call this method from elsewhere (e.g. static files or tasks),
            // where suspending would throw a fatal error.
            if (\Fiber::getCurrent() === $this->handleCurrentFiber) {
                if (! $this->currentFiberHasResponded) {
                    $this->currentFiberHasResponded = true;

                    \Fiber::suspend($octaneResponse);
                }

                return;
            }
        }

        $this->response = $octaneResponse;
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
