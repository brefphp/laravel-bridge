<?php

namespace Bref\LaravelBridge\Octane;

use Bref\Bref;
use Bref\Event\Handler;
use Bref\Context\Context;
use Bref\Listener\BrefEventSubscriber;
use Throwable;

use Laravel\Octane\Worker;
use Laravel\Octane\RequestContext;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\ApplicationFactory;

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Response;

class OctaneClient implements Client
{
    /**
     * The Octane worker.
     */
    private Worker $worker;

    protected \Fiber|null $handleCurrentFiber = null;
    protected bool $currentFiberHasResponded = false;

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

        Bref::events()->subscribe(
            new class ($this) extends BrefEventSubscriber {
                public function __construct(protected OctaneClient $self)
                {
                }

                public function afterInvoke(
                    callable|Handler|RequestHandlerInterface $handler,
                    mixed $event,
                    Context $context,
                    mixed $result,
                    ?Throwable $error = null
                ): void { // We listen to the afterInvoke method here so we can finish the fiber
                    $this->self->ensureExistingFiberIsTerminated();
                }
            }
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
        if (Bref::isRunningInStreamingMode()) {
            if (Bref::doesStreamingSupportsFibers()) {
                $this->ensureExistingFiberIsTerminated();

                return $this->handleFiberableRequest($request);
            }
        }

        $this->worker->application()->useStoragePath('/tmp/storage');

        $this->worker->handle($request, new RequestContext());

        $response = clone $this->response->response;
        $this->response = null;

        return $response;
    }

    public function ensureExistingFiberIsTerminated()
    {
        if (($currentFiber = $this->handleCurrentFiber) instanceof \Fiber) {
            if ($currentFiber->isStarted()) {
                while (! $currentFiber->isTerminated()) {
                    $currentFiber->resume();
                }
            }

            $this->handleCurrentFiber = null;
        }

        $this->currentFiberHasResponded = false;
    }

    protected function handleFiberableRequest(Request $request): Response
    {
        $this->handleCurrentFiber = new \Fiber(
            function () use (&$request) {
                $this->worker->application()->useStoragePath('/tmp/storage');

                $this->worker->handle($request, new RequestContext());
            }
        );

        /**
         * @var \Laravel\Octane\OctaneResponse $octaneResponse
         */
        $octaneResponse = $this->handleCurrentFiber->start();

        return $octaneResponse->response;
    }

    /**
     * {@inheritdoc}
     */
    public function error(Throwable $exception, Application $app, Request $request, RequestContext $context): void
    {
        try {
            $response = new OctaneResponse(
                $app[ExceptionHandler::class]->render($request, $exception)
            );
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage());
            fwrite(STDERR, $exception->getMessage());

            $response = new OctaneResponse(
                new Response('Internal Server Error', 500)
            );
        }

        if (Bref::isRunningInStreamingMode()) {
            if (Bref::doesStreamingSupportsFibers()) {
                if (! $this->currentFiberHasResponded) {
                    $this->currentFiberHasResponded = true;
                    \Fiber::suspend($response); // If we are running in streaming mode and we support fiber, we suspend the response
                } else {
                    fwrite(STDERR, "Request failed and already started sending: " . $exception->getMessage());
                }
                return;
            } else {
                fwrite(STDERR, "Request running in Octane mode with streaming but no Fibers support, that can cause unwanted errors like Laravel's Container not booted");
            }
        }

        $this->response = $response;
    }

    /**
     * {@inheritdoc}
     */
    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        if (Bref::isRunningInStreamingMode()) {
            if (Bref::doesStreamingSupportsFibers()) {
                if (! $this->currentFiberHasResponded) {
                    $this->currentFiberHasResponded = true;
                    \Fiber::suspend($response); // If we are running in streaming mode and we support fiber, we suspend the response
                }
                return;
            } else {
                fwrite(STDERR, "Request running in Octane mode with streaming but no Fibers support, that can cause unwanted errors like Laravel's Container not booted");
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
