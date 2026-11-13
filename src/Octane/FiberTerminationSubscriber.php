<?php

namespace Bref\LaravelBridge\Octane;

use Bref\Context\Context;
use Bref\Event\Handler;
use Bref\Listener\BrefEventSubscriber;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Finishes the Octane request whose streamed body was not sent, once the Lambda invocation has ended
 * (e.g. it failed before Bref sent the response), instead of waiting for the next request.
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
