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
    protected static $worker;

    protected static $response;

    public static function boot($basePath)
    {
        static::$worker = tap(
            new Worker(new ApplicationFactory($basePath), new self)
        )->boot();
    }

    public static function handle(Request $request): Response
    {
        static::$worker->application()->useStoragePath('/tmp/storage');

        $context = new RequestContext;

        static::$worker->handle($request, $context);

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
