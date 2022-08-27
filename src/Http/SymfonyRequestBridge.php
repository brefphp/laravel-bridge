<?php

namespace CacheWerk\BrefLaravelBridge\Http;

use RuntimeException;

use Bref\Context\Context;
use Bref\Event\Http\HttpRequestEvent;

use Riverline\MultiPartParser\StreamedPart;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SymfonyRequestBridge
{
    /**
     * Convert Bref HTTP request to Symfony request.
     *
     * @param  \Bref\Event\Http\HttpRequestEvent  $event
     * @param  \Bref\Context\Context  $context
     * @return \Symfony\Component\HttpFoundation\Request
     */
    public static function convertRequest(HttpRequestEvent $event, Context $context): Request
    {
        $headers = $event->getHeaders();

        [$user, $password] = self::parseBasicAuthorization($headers);

        $server = array_filter([
            'AUTH_TYPE' => $headers['auth-type'] ?? null,
            'CONTENT_LENGTH' => $headers['content-length'][0] ?? null,
            'CONTENT_TYPE' => $event->getContentType(),
            'DOCUMENT_ROOT' => getcwd(),
            'GATEWAY_INTERFACE' => 'FastCGI/1.0',
            'QUERY_STRING' => $event->getQueryString(),
            'REQUEST_METHOD' => $event->getMethod(),
            'SCRIPT_FILENAME' => $_SERVER['_HANDLER'] ?? $_SERVER['SCRIPT_FILENAME'],
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_NAME' => $event->getServerName(),
            'SERVER_PORT' => $event->getServerPort(),
            'SERVER_PROTOCOL' => $event->getProtocol(),
            'PATH_INFO' => $event->getPath(),
            'REMOTE_PORT' => $event->getRemotePort(),
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'REQUEST_URI' => $event->getUri(),
            'REMOTE_ADDR' => '127.0.0.1',
            'LAMBDA_INVOCATION_CONTEXT' => json_encode($context),
            'LAMBDA_REQUEST_CONTEXT' => json_encode($event->getRequestContext()),
            'HTTP_X_SOURCE_IP' => $event->getSourceIp(),
            'HTTP_X_REQUEST_ID' => $context->getAwsRequestId(),
            'PHP_AUTH_USER' => $user,
            'PHP_AUTH_PW' => $password,
        ], fn ($value) => ! is_null($value));

        foreach ($headers as $name => $values) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $values[0];
        }

        [$files, $parsedBody, $bodyString] = self::parseBodyAndUploadedFiles($event);

        return new Request(
            $event->getQueryParameters(),
            $parsedBody ?? [],
            [],
            $event->getCookies(),
            $files,
            $server,
            $bodyString
        );
    }

    /**
     * Parse request body and uploaded files.
     *
     * @param  \Bref\Event\Http\HttpRequestEvent  $event
     * @return array
     */
    protected static function parseBodyAndUploadedFiles(HttpRequestEvent $event): array
    {
        $bodyString = $event->getBody();
        $files = [];
        $parsedBody = null;
        $contentType = $event->getContentType();

        if (null !== $contentType && 'POST' === $event->getMethod()) {
            if ('application/x-www-form-urlencoded' === $contentType) {
                parse_str($bodyString, $parsedBody);
            } else {
                $stream = fopen('php://temp', 'rw');
                fwrite($stream, "Content-type: $contentType\r\n\r\n".$bodyString);
                rewind($stream);

                $document = new StreamedPart($stream);

                if ($document->isMultiPart()) {
                    $bodyString = '';
                    $parsedBody = [];
                    foreach ($document->getParts() as $part) {
                        if ($part->isFile()) {
                            $tmpPath = tempnam(sys_get_temp_dir(), 'bref_upload_');
                            if (false === $tmpPath) {
                                throw new RuntimeException('Unable to create a temporary directory');
                            }
                            file_put_contents($tmpPath, $part->getBody());
                            if (0 !== filesize($tmpPath) && '' !== $part->getFileName()) {
                                $file = new UploadedFile($tmpPath, $part->getFileName(), $part->getMimeType(), UPLOAD_ERR_OK, true);
                            } else {
                                $file = null;
                            }

                            self::parseKeyAndInsertValueInArray($files, $part->getName(), $file);
                        } else {
                            self::parseKeyAndInsertValueInArray($parsedBody, $part->getName(), $part->getBody());
                        }
                    }
                }
            }
        }

        return [$files, $parsedBody, $bodyString];
    }

    /**
     * Parse a string key like "files[id_cards][jpg][]" and do $array['files']['id_cards']['jpg'][] = $value.
     *
     * @param  array  $value
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    protected static function parseKeyAndInsertValueInArray(array &$array, string $key, $value): void
    {
        if (false === strpos($key, '[')) {
            $array[$key] = $value;

            return;
        }

        $parts = explode('[', $key); // files[id_cards][jpg][] => [ 'files',  'id_cards]', 'jpg]', ']' ]
        $pointer = &$array;

        foreach ($parts as $k => $part) {
            if (0 === $k) {
                $pointer = &$pointer[$part];

                continue;
            }

            // Skip two special cases:
            // [[ in the key produces empty string
            // [test : starts with [ but does not end with ]
            if ('' === $part || ']' !== substr($part, -1)) {
                // Malformed key, we use it "as is"
                $array[$key] = $value;

                return;
            }

            $part = substr($part, 0, -1); // The last char is a ] => remove it to have the real key

            if ('' === $part) { // [] case
                $pointer = &$pointer[];
            } else {
                $pointer = &$pointer[$part];
            }
        }

        $pointer = $value;
    }

    /**
     * Parse the username and password from the `Authorization` header.
     * Only "Basic" is supported.
     *
     * @param  array  $headers
     * @return string[]|null[]
     */
    protected static function parseBasicAuthorization(array $headers)
    {
        $authorization = trim($headers['authorization'] ?? '');

        if (! str_starts_with($authorization, 'Basic ')) {
            return [null, null];
        }

        $auth = base64_decode(trim(explode(' ', $authorization)[1]));

        if (! $auth || ! strpos($auth, ':')) {
            return [null, null];
        }

        return [
            strstr($auth, ':', true),
            substr(strstr($auth, ':'), 1),
        ];
    }
}
