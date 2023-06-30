<?php

namespace Bref\LaravelBridge\Http;

use Bref\LaravelBridge\MaintenanceMode;
use Bref\LaravelBridge\Octane\OctaneClient;

use Illuminate\Http\Request;

use Bref\Context\Context;
use Bref\Event\Http\HttpHandler;
use Bref\Event\Http\HttpResponse;
use Bref\Event\Http\HttpRequestEvent;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OctaneHandler extends HttpHandler
{
    private OctaneClient $octaneClient;

    public function __construct(?string $path = null)
    {
        $this->octaneClient = new OctaneClient(
            $path ?? getcwd(),
            (bool) ($_ENV['OCTANE_PERSIST_DATABASE_SESSIONS'] ?? false)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function handleRequest(HttpRequestEvent $event, Context $context): HttpResponse
    {
        $request = Request::createFromBase(
            SymfonyRequestBridge::convertRequest($event, $context)
        );

        if (MaintenanceMode::active()) {
            $response = MaintenanceMode::response($request)->prepare($request);
        } else {
            $response = $this->octaneClient->handle($request);
        }

        if (! $response->headers->has('Content-Type')) {
            $response->prepare($request); // https://github.com/laravel/framework/pull/43895
        }

        $content = $response instanceof BinaryFileResponse
            ? $response->getFile()->getContent()
            : $response->getContent();

        return new HttpResponse(
            $content,
            $response->headers->all(),
            $response->getStatusCode()
        );
    }
}
