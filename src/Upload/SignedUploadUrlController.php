<?php

namespace Bref\LaravelBridge\Upload;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Returns a presigned S3 URL that the browser can use to upload a file directly to S3.
 *
 * The response is compatible with Laravel Vapor's `/vapor/signed-storage-url` endpoint.
 */
class SignedUploadUrlController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        // Guests are denied unless the gate explicitly accepts a nullable user
        Gate::authorize('uploadFiles');

        // UploadedToS3 can filter the declared type via the key extension; it does not inspect file contents
        $validated = $request->validate([
            'content_type' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ]);

        $contentType = $validated['content_type'] ?? 'application/octet-stream';
        $extension = self::extensionForContentType($contentType);
        $uuid = (string) Str::uuid();
        $key = Uploads::directory($user) . '/' . $uuid . ($extension !== null ? ".$extension" : '');

        $disk = Uploads::disk();
        $expires = (int) config('bref.uploads.expires', 5);

        /** @var array{url: string, headers: array<string, string|string[]>} $uploadUrl */
        $uploadUrl = Storage::disk($disk)->temporaryUploadUrl($key, now()->addMinutes($expires), [
            'ContentType' => $contentType,
        ]);

        $headers = ['Content-Type' => $contentType];
        foreach ($uploadUrl['headers'] as $name => $values) {
            // Browsers forbid setting the Host header
            if (strtolower($name) === 'host') {
                continue;
            }
            // PSR-7 headers are `string[]`
            $name = strtolower($name) === 'content-type' ? 'Content-Type' : $name;
            $headers[$name] = is_array($values) ? implode(', ', $values) : $values;
        }

        return new JsonResponse([
            'uuid' => $uuid,
            'key' => $key,
            'bucket' => config("filesystems.disks.$disk.bucket"),
            'url' => $uploadUrl['url'],
            'headers' => $headers,
            'extension' => $extension,
        ], 201);
    }

    /**
     * Derive a file extension from a MIME type, or null when unknown.
     */
    private static function extensionForContentType(string $contentType): ?string
    {
        $contentType = strtolower(trim(explode(';', $contentType, 2)[0]));

        // Generic binary content: keep the key without extension (like Vapor) rather than `.bin`
        if ($contentType === 'application/octet-stream') {
            return null;
        }

        $extension = MimeTypes::getDefault()->getExtensions($contentType)[0] ?? null;

        // Only keep extensions that are safe to put in an S3 key and to match in the validation rule
        if ($extension === null || ! preg_match('/^[a-z0-9]+(\.[a-z0-9]+)*$/i', $extension)) {
            return null;
        }

        return $extension;
    }
}
