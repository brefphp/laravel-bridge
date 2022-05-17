<?php

namespace CacheWerk\BrefLaravelBridge\Http;

use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel;

use Bref\Context\Context;
use Bref\Event\Http\HttpResponse;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\Http\HttpHandler as BrefHttpHandler;

class HttpHandler extends BrefHttpHandler
{
    protected $kernel;

    public function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;
    }

    public function handleRequest(?HttpRequestEvent $event, ?Context $context): HttpResponse
    {
        $request = Request::createFromBase(
            RequestBridge::convertRequest($event, $context)
        );

        $response = $this->kernel->handle($request);

        $this->kernel->terminate($request, $response);

        $response->prepare($request);

        return new HttpResponse(
            $response->getContent(),
            $response->headers->all(),
            $response->getStatusCode()
        );
    }
}

