<?php

namespace Bref\LaravelBridge\Http;

use Bref\LaravelBridge\MaintenanceMode;
use Bref\LaravelBridge\Octane\OctaneClient;

use Illuminate\Http\Request;

use Bref\Context\Context;
use Bref\Event\Http\HttpHandler;
use Bref\Event\Http\HttpResponse;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\Http\StreamedHttpResponse;
use Generator;
use ReflectionFunction;
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
    public function handleRequest(HttpRequestEvent $event, Context $context): HttpResponse|StreamedHttpResponse
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

        if (
            ($response instanceof StreamedResponse) &&
            ($responseCallback = $response->getCallback()) &&
            ((new ReflectionFunction($responseCallback))->getReturnType()?->getName() === Generator::class)
        ) {
            return new StreamedHttpResponse(
                $responseCallback(),
                $response->headers->all(),
                $response->getStatusCode()
            );
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
