<?php

namespace Bref\LaravelBridge\Http;

use Bref\Context\Context;
use Bref\Event\Http\Psr7Bridge;
use Bref\Event\Http\HttpRequestEvent;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;

class SymfonyRequestBridge
{
    /**
     * Convert Bref HTTP event to Symfony request.
     *
     * @param  \Bref\Event\Http\HttpRequestEvent  $event
     * @param  \Bref\Context\Context  $context
     * @return \Symfony\Component\HttpFoundation\Request
     */
    public static function convertRequest(HttpRequestEvent $event, Context $context): Request
    {
        $psr7Request = Psr7Bridge::convertRequest($event, $context);
        $httpFoundationFactory = new HttpFoundationFactory();
        $symfonyRequest = $httpFoundationFactory->createRequest($psr7Request);

        $symfonyRequest->server->add([
            'HTTP_X_REQUEST_ID' => $context->getAwsRequestId(),
            'LAMBDA_INVOCATION_CONTEXT' => json_encode($context),
            'LAMBDA_REQUEST_CONTEXT' => json_encode($event->getRequestContext()),
        ]);

        return $symfonyRequest;
    }
}
