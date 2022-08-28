<?php

namespace CacheWerk\BrefLaravelBridge\Http;

use CacheWerk\BrefLaravelBridge\MaintenanceMode;
use CacheWerk\BrefLaravelBridge\Octane\OctaneClient;

use Illuminate\Http\Request;

use Bref\Context\Context;
use Bref\Event\Http\HttpHandler;
use Bref\Event\Http\HttpResponse;
use Bref\Event\Http\HttpRequestEvent;

class OctaneHandler extends HttpHandler
{
    /**
     * {@inheritDoc}
     */
    public function handleRequest(?HttpRequestEvent $event, ?Context $context): HttpResponse
    {
        $request = Request::createFromBase(
            SymfonyRequestBridge::convertRequest($event, $context)
        );

        if (MaintenanceMode::active()) {
            $response = MaintenanceMode::response($request)->prepare($request);
        } else {
            $response = OctaneClient::handle($request);
        }

        if (! $response->headers->has('Content-Type')) {
            $response->prepare($request);
        }

        return new HttpResponse(
            $response->getContent(),
            $response->headers->all(),
            $response->getStatusCode()
        );
    }
}
