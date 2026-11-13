<?php

namespace Bref\LaravelBridge\Http;

use Bref\LaravelBridge\MaintenanceMode;
use Bref\LaravelBridge\Octane\OctaneClient;

use Illuminate\Http\Request;

use Bref\Context\Context;
use Bref\Event\Http\HttpHandler;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\Http\HttpResponseWriter;
use Bref\Event\Http\Psr7Bridge;

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
    protected function respond(HttpRequestEvent $event, Context $context, HttpResponseWriter $writer): void
    {
        // Delete the files uploaded with the previous request, like Bref's Psr15Handler does:
        // the Lambda instance is reused, and its /tmp would otherwise keep every upload until it is full.
        Psr7Bridge::cleanupUploadedFiles();

        $request = Request::createFromBase(
            SymfonyRequestBridge::convertRequest($event, $context)
        );

        if (MaintenanceMode::active()) {
            OctaneClient::send(MaintenanceMode::response($request), $request, $writer);
            return;
        }

        $this->octaneClient->handle($request, $writer);
    }
}
