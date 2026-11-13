<?php

namespace Bref\LaravelBridge\Octane;

use Bref\Bref;
use Fiber;
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
    private OctaneResponse|null $response = null;

    /**
     * Handles the current request. It is suspended with a streamed response before its body is sent,
     * and resumed with the function that sends each chunk (see sendStreamedBody()).
     */
    private Fiber|null $requestFiber = null;

    /**
     * The exception thrown while sending the last streamed body, if any.
     */
    private Throwable|null $streamError = null;

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
     * A streamed response is returned before its body is produced: send it with sendStreamedBody().
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request): Response
    {
        $this->ensureExistingFiberIsTerminated();

        $this->requestFiber = new Fiber(function () use ($request): void {
            $this->worker->application()->useStoragePath('/tmp/storage');

            $this->worker->handle($request, new RequestContext());
        });
        $streamed = $this->requestFiber->start();
        if ($streamed instanceof StreamedResponse) {
            return $streamed;
        }
        $this->requestFiber = null;

        if (! $this->response instanceof OctaneResponse) {
            throw new RuntimeException('Octane handled the request without responding.');
        }

        $response = clone $this->response->response;
        $this->response = null;

        return $response;
    }

    /**
     * Send the body of the streamed response returned by handle(): Octane resumes, runs the response's
     * callback, then finishes the request (terminate callbacks, sandbox flush).
     *
     * @param callable(string): mixed $write Sends a chunk of the body.
     */
    public function sendStreamedBody(callable $write): void
    {
        $fiber = $this->requestFiber;
        $this->requestFiber = null;
        $fiber?->resume($write);
        $this->response = null;

        // Octane only reports it: rethrow it so that Bref reports the failed invocation too
        [$error, $this->streamError] = [$this->streamError, null];
        if ($error) {
            throw $error;
        }
    }

    /**
     * Finish the request whose streamed body was never sent (e.g. the invocation failed before):
     * Octane must end it before handling another one.
     */
    public function ensureExistingFiberIsTerminated(): void
    {
        if (! $this->requestFiber?->isSuspended()) {
            return;
        }
        try {
            $this->sendStreamedBody(fn () => false);
        } catch (Throwable $e) {
            fwrite(STDERR, 'The streamed response that was not sent failed: ' . $e->getMessage() . PHP_EOL);
        }
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
        // Streamed responses are sent while their callback runs, inside the request (see handle()).
        // Octane may respond outside of it (e.g. static files): there is no request Fiber to suspend then.
        if ($response->response instanceof StreamedResponse && $this->requestFiber !== null && Fiber::getCurrent() === $this->requestFiber) {
            // Hand the status and headers over: sendStreamedBody() resumes with the function that sends a chunk
            $write = Fiber::suspend($response->response);
            // Each output is sent from the output handler itself: no Fiber switch in there, PHP would
            // consider any output from outside as output from the handler
            ob_start(function (string $buffer) use ($write): string {
                if ($buffer !== '') {
                    $write($buffer);
                }

                return '';
            }, 1);
            try {
                $response->response->sendContent();
            } catch (Throwable $e) {
                $this->streamError = $e;
                throw $e;
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
