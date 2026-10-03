<?php

namespace Bref\LaravelBridge\Octane;

use Bref\Context\Context;
use Bref\Event\Handler;
use Bref\Listener\BrefEventSubscriber;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Terminates the Octane request fiber once the Lambda invocation has ended.
 *
 * When running in streaming mode, the fiber handling the request is suspended
 * while the response body is streamed to the client. This subscriber resumes
 * the fiber after the invocation so that Octane can finish the request
 * lifecycle (request handled callbacks, request termination, sandbox flush).
 *
 * @internal
 */
class FiberTerminationSubscriber extends BrefEventSubscriber
{
    public function __construct(protected OctaneClient $client)
    {
    }

    public function afterInvoke(
        callable|Handler|RequestHandlerInterface $handler,
        mixed $event,
        Context $context,
        mixed $result,
        ?Throwable $error = null,
    ): void {
        $this->client->ensureExistingFiberIsTerminated();
    }
}
