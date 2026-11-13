<?php

namespace Bref\LaravelBridge\Http;

use Generator;
use SplFileInfo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extracts the body of a Symfony response to send to Lambda (streamed responses are sent by the Octane client).
 *
 * @internal
 */
final class ResponseBodyExtractor
{
    private const FILE_CHUNK_SIZE = 64 * 1024;

    private function __construct()
    {
    }

    /**
     * @return string|Generator<string>
     */
    public static function extract(Response $response): string|Generator
    {
        if ($response instanceof BinaryFileResponse) {
            // Avoid loading the whole file into memory
            return self::streamFile($response->getFile());
        }

        return (string) $response->getContent();
    }

    /**
     * BinaryFileResponse is always a real path: Symfony normalizes any
     * SplFileInfo into a regular file at construction time, so reading the
     * path (like Symfony's own sendContent() does) is always correct here.
     * A missing file yields an empty body instead of failing.
     *
     * @return Generator<string>
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
}
