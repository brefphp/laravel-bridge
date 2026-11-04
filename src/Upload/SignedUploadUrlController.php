<?php

namespace Bref\LaravelBridge\Upload;

use Aws\S3\PostObjectV4;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Returns a presigned S3 POST form that the browser can use to upload a file directly to S3.
 *
 * Unlike a presigned PUT URL, a presigned POST lets S3 enforce the maximum size (`bref.uploads.max_size`)
 * and the content type: S3 rejects any other upload.
 *
 * @internal Registered by the service provider on the route configured in `bref.uploads.route`.
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
        $storage = Storage::disk($disk);
        if (! $storage instanceof AwsS3V3Adapter) {
            throw new LogicException("The `$disk` disk used for uploads must be an S3 disk (configure it in `bref.uploads.disk`)");
        }
        $bucket = (string) config("filesystems.disks.$disk.bucket");
        $expires = (int) config('bref.uploads.expires', 5);
        $maxSize = config('bref.uploads.max_size', 50 * 1024 * 1024);

        // Includes the `root` of the disk, if any
        $s3Key = $storage->path($key);

        // S3 rejects uploads that don't match these conditions
        $conditions = [
            ['bucket' => $bucket],
            ['key' => $s3Key],
            ['Content-Type' => $contentType],
        ];
        if ($maxSize !== null) {
            $conditions[] = ['content-length-range', 0, (int) $maxSize];
        }

        $post = new PostObjectV4($storage->getClient(), $bucket, [
            'key' => $s3Key,
            'Content-Type' => $contentType,
        ], $conditions, "+$expires minutes");

        return new JsonResponse([
            'uuid' => $uuid,
            'key' => $key,
            'bucket' => $bucket,
            'url' => $post->getFormAttributes()['action'],
            // Form fields to send, before the file, in a `multipart/form-data` POST request to `url`
            'fields' => $post->getFormInputs(),
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
