<?php

namespace Bref\LaravelBridge\Upload;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Helpers to work with files uploaded to S3 via presigned URLs.
 */
class Uploads
{
    /**
     * Move a temporary upload to its final location.
     *
     * S3 has no rename operation: the object is copied, then the temporary one is deleted.
     *
     * @param string $key The temporary key returned by the signed upload URL endpoint (e.g. `tmp/1/uuid.pdf`).
     * @param string $destination The final path on the disk.
     * @param string|null $disk The disk to use (null = the disk configured in `bref.uploads.disk`).
     *
     * @throws RuntimeException When the file could not be copied.
     */
    public static function move(string $key, string $destination, ?string $disk = null): void
    {
        $storage = Storage::disk($disk ?? self::disk());

        if (! $storage->copy($key, $destination)) {
            throw new RuntimeException("Could not move the upload \"$key\" to \"$destination\".");
        }

        // Best effort: the S3 lifecycle rule on the temporary prefix is the safety net.
        // Disks configured with `throw => true` throw instead of returning false.
        try {
            $storage->delete($key);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The directory (without trailing slash) in which temporary uploads are stored:
     * `{prefix}/{user id}` for authenticated users, `{prefix}` for guests.
     */
    public static function directory(?Authenticatable $user): string
    {
        $prefix = trim((string) config('bref.uploads.prefix', 'tmp'), '/');

        if ($user === null) {
            return $prefix;
        }

        return $prefix . '/' . $user->getAuthIdentifier();
    }

    /**
     * The name of the disk used for uploads.
     */
    public static function disk(): string
    {
        return config('bref.uploads.disk') ?? config('filesystems.default');
    }
}
