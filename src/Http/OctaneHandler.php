<?php

namespace Bref\LaravelBridge\Http;

use Bref\LaravelBridge\MaintenanceMode;
use Bref\LaravelBridge\Octane\OctaneClient;

use Illuminate\Http\Request;

use Bref\Context\Context;
use Bref\Event\Http\HttpHandler;
use Bref\Event\Http\HttpResponse;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\Http\Psr7Bridge;

use Fiber;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // Delete the files uploaded with the previous request, like Bref's Psr15Handler does:
        // the Lambda instance is reused, and its /tmp would otherwise keep every upload until it is full.
        Psr7Bridge::cleanupUploadedFiles();

        $request = Request::createFromBase(
            SymfonyRequestBridge::convertRequest($event, $context)
        );

        if (MaintenanceMode::active()) {
            $response = MaintenanceMode::response($request)->prepare($request);
        } else {
            // In a Fiber so that a streamed response can be sent while Laravel produces it (see OctaneClient::respond())
            $fiber = new Fiber(fn () => $this->octaneClient->handle($request));
            $streamed = $fiber->start();
            if ($streamed instanceof StreamedResponse) {
                // Bref calls it with the function that sends a chunk: Octane resumes, runs the callback and ends the request
                $body = function (callable $write) use ($fiber): void {
                    $fiber->resume($write);
                    $error = $this->octaneClient->takeStreamError();
                    if ($error) {
                        throw $error;
                    }
                };

                return new HttpResponse($body, $streamed->headers->all(), $streamed->getStatusCode());
            }
            $response = $fiber->getReturn();
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
