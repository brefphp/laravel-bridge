<?php

namespace Bref\LaravelBridge\Http;

use Generator;
use ReflectionFunction;
use ReflectionNamedType;
use SplFileInfo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Extracts the body of a Symfony response to send to Lambda.
 *
 * Generator bodies are returned as-is so that Bref can stream each yielded
 * chunk to the client. All other bodies are returned as a plain string.
 *
 * @internal
 */
final class ResponseBodyExtractor
{
    private const FILE_CHUNK_SIZE = 64 * 1024;

    private function __construct()
    {
    }

    public static function extract(Response $response): string|Generator
    {
        if ($response instanceof BinaryFileResponse) {
            // Avoid loading the whole file into memory when responses are streamed.
            if (self::isStreamingEnabled()) {
                return self::streamFile($response->getFile());
            }

            return $response->getFile()->getContent();
        }

        if ($response instanceof StreamedResponse && $callback = $response->getCallback()) {
            return self::extractStreamedResponse($callback);
        }

        return $response->getContent();
    }

    /**
     * BinaryFileResponse is always a real path: Symfony normalizes any
     * SplFileInfo into a regular file at construction time, so reading the
     * path (like Symfony's own sendContent() does) is always correct here.
     * A missing file yields an empty body instead of failing.
     */
    private static function streamFile(SplFileInfo $file): Generator
    {
        $handle = @fopen($file->getPathname(), 'rb');

        if (! $handle) {
            return;
        }

        try {
            while (($chunk = fread($handle, self::FILE_CHUNK_SIZE)) !== '' && $chunk !== false) {
                yield $chunk;
            }
        } finally {
            fclose($handle);
        }
    }

    private static function isStreamingEnabled(): bool
    {
        return (bool) getenv('BREF_STREAMED_MODE');
    }

    private static function extractStreamedResponse(callable $callback): string|Generator
    {
        $returnType = (new ReflectionFunction($callback))->getReturnType();

        // Calling a generator function never executes its body, so inspecting
        // the returned value is safe even when the return type is not declared.
        if ($returnType === null || ($returnType instanceof ReflectionNamedType && in_array($returnType->getName(), [Generator::class, \Iterator::class, \Traversable::class], true))) {
            ob_start();
            $returned = $callback();
            $output = (string) ob_get_clean();

            if ($returned instanceof Generator) {
                return $returned;
            }

            // Not a generator: it must be an echo-style callback, whose output
            // would otherwise be lost.
            return $output;
        }

        // Echo-style callbacks (declared "void", etc.) need their output
        // captured: it would otherwise be lost.
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
