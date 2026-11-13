<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Http;

use Bref\LaravelBridge\Http\ResponseBodyExtractor;
use Generator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class ResponseBodyExtractorTest extends TestCase
{
    public function testRegularResponseBody(): void
    {
        $body = ResponseBodyExtractor::extract(new Response('Hello world', 200, ['Content-Type' => 'text/plain']));

        self::assertSame('Hello world', $body);
    }

    public function testBinaryFileResponseIsStreamed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bref-');
        file_put_contents($file, str_repeat('chunk', 100_000));

        try {
            $body = ResponseBodyExtractor::extract(new BinaryFileResponse($file));

            self::assertInstanceOf(Generator::class, $body);
            $chunks = iterator_to_array($body, false);
            self::assertGreaterThan(1, count($chunks));
            self::assertSame(str_repeat('chunk', 100_000), implode('', $chunks));
        } finally {
            unlink($file);
        }
    }

    public function testDeletedFileYieldsEmptyBody(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bref-');
        file_put_contents($file, 'content');

        $response = new BinaryFileResponse($file);
        unlink($file);

        $body = ResponseBodyExtractor::extract($response);

        self::assertInstanceOf(Generator::class, $body);
        self::assertSame('', implode('', iterator_to_array($body, false)));
    }
}
