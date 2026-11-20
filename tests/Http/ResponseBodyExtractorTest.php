<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Http;

use Bref\LaravelBridge\Http\ResponseBodyExtractor;
use Generator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ResponseBodyExtractorTest extends TestCase
{
    public function testRegularResponseBody(): void
    {
        $body = ResponseBodyExtractor::extract(new Response('Hello world', 200, ['Content-Type' => 'text/plain']));

        self::assertSame('Hello world', $body);
    }

    public function testStreamedResponseWithDeclaredGeneratorCallback(): void
    {
        $response = new StreamedResponse(function (): Generator {
            yield 'chunk 1';
            yield 'chunk 2';
        });

        $body = ResponseBodyExtractor::extract($response);

        self::assertInstanceOf(Generator::class, $body);
        self::assertSame('chunk 1chunk 2', implode('', iterator_to_array($body)));
    }

    public function testStreamedResponseWithUndeclaredGeneratorCallback(): void
    {
        $response = new StreamedResponse(function () {
            yield 'chunk 1';
            yield 'chunk 2';
        });

        $body = ResponseBodyExtractor::extract($response);

        self::assertInstanceOf(Generator::class, $body);
        self::assertSame('chunk 1chunk 2', implode('', iterator_to_array($body)));
    }

    public function testStreamedResponseWithEchoCallbackIsBuffered(): void
    {
        $response = new StreamedResponse(function (): void {
            echo 'streamed content';
        });

        $body = ResponseBodyExtractor::extract($response);

        self::assertSame('streamed content', $body);
    }

    public function testStreamedResponseWithoutCallbackHasEmptyBody(): void
    {
        $response = new StreamedResponse();

        $body = ResponseBodyExtractor::extract($response);

        self::assertSame('', $body);
    }

    public function testBinaryFileResponseBody(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bref-');
        file_put_contents($file, 'binary-content');

        try {
            $body = ResponseBodyExtractor::extract(new BinaryFileResponse($file));

            self::assertSame('binary-content', $body);
        } finally {
            unlink($file);
        }
    }

    public function testBinaryFileResponseIsStreamedInStreamingMode(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bref-');
        file_put_contents($file, str_repeat('chunk', 100_000));

        $originalEnv = getenv('BREF_STREAMED_MODE');
        putenv('BREF_STREAMED_MODE=1');

        try {
            $body = ResponseBodyExtractor::extract(new BinaryFileResponse($file));

            self::assertInstanceOf(Generator::class, $body);
            self::assertSame(str_repeat('chunk', 100_000), implode('', iterator_to_array($body)));
        } finally {
            $originalEnv === false ? putenv('BREF_STREAMED_MODE=') : putenv("BREF_STREAMED_MODE=$originalEnv");
            unlink($file);
        }
    }

    public function testDeletedFileYieldsEmptyBodyInStreamingMode(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bref-');
        file_put_contents($file, 'content');

        $response = new BinaryFileResponse($file);
        unlink($file);

        $originalEnv = getenv('BREF_STREAMED_MODE');
        putenv('BREF_STREAMED_MODE=1');

        try {
            $body = ResponseBodyExtractor::extract($response);

            self::assertInstanceOf(Generator::class, $body);
            self::assertSame('', implode('', iterator_to_array($body)));
        } finally {
            $originalEnv === false ? putenv('BREF_STREAMED_MODE=') : putenv("BREF_STREAMED_MODE=$originalEnv");
        }
    }
}
