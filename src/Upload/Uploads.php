<?php

namespace Bref\LaravelBridge\Upload;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Where temporary uploads are stored, shared by the controller and the validation rule.
 *
 * @internal
 */
class Uploads
{
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
