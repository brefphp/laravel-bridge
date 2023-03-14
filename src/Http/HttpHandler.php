<?php

namespace Bref\LaravelBridge\Http;

use Bref\LaravelBridge\MaintenanceMode;

use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel;

use Bref\Context\Context;
use Bref\Event\Http\HttpResponse;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\Http\HttpHandler as BrefHttpHandler;

class HttpHandler extends BrefHttpHandler
{
    /**
     * Creates a new instance.
     *
     * @param  \Illuminate\Contracts\Http\Kernel  $kernel
     * @return void
     */
    public function __construct(
        protected Kernel $kernel
    ) {
    }

    /**
     * Handle given HTTP request event.
     *
     * @param  \Bref\Event\Http\HttpRequestEvent  $event
     * @param  \Bref\Context\Context  $context
     * @return \Bref\Event\Http\HttpResponse
     */
    public function handleRequest(?HttpRequestEvent $event, ?Context $context): HttpResponse
    {
        $request = Request::createFromBase(
            SymfonyRequestBridge::convertRequest($event, $context)
        );

        if (MaintenanceMode::active()) {
            $response = MaintenanceMode::response($request);
        } else {
            $response = $this->kernel->handle($request);
        }

        $this->kernel->terminate($request, $response);

        $response->prepare($request);

        return new HttpResponse(
            $response->getContent(),
            $response->headers->all(),
            $response->getStatusCode()
        );
    }
}
