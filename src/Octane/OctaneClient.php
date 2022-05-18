<?php

namespace CacheWerk\BrefLaravelBridge\Octane;

use Throwable;

use Laravel\Octane\Worker;
use Laravel\Octane\RequestContext;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\ApplicationFactory;

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;

use Symfony\Component\HttpFoundation\Response;

class OctaneClient implements Client
{
    /**
     * The Octane worker.
     *
     * @var \Laravel\Octane\Worker
     */
    protected static $worker;

    /**
     * The Octane response.
     *
     * @var \Laravel\Octane\OctaneResponse
     */
    protected static $response;

    /**
     * Boots an Octane worker instance.
     *
     * @param  string  $basePath
     * @return void
     */
    public static function boot(string $basePath)
    {
        static::$worker = tap(
            new Worker(new ApplicationFactory($basePath), new self)
        )->boot();
    }

    /**
     * Handle the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function handle(Request $request): Response
    {
        static::$worker->application()->useStoragePath('/tmp/storage');

        static::$worker->handle($request, new RequestContext);

        $response = clone static::$response->response;
        static::$response = null;

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function error(Throwable $exception, Application $app, Request $request, RequestContext $context): void
    {
        try {
            static::$response = new OctaneResponse(
                $app[ExceptionHandler::class]->render($request, $exception)
            );
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage());
            fwrite(STDERR, $exception->getMessage());

            static::$response = new OctaneResponse(
                new Response('Internal Server Error', 500)
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        static::$response = $response;
    }

    /**
     * {@inheritdoc}
     */
    public function marshalRequest(RequestContext $context): array
    {
        //
    }
}
