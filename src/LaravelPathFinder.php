<?php

namespace Bref\LaravelBridge;

use RuntimeException;

final class LaravelPathFinder
{
    /**
     * Resolve the Laravel root path. Path is always returned without a leading slash.
     *
     * @return string
     */
    public static function root(): string
    {
        $app = $_SERVER['LAMBDA_TASK_ROOT'] . '/bootstrap/app.php';

        // If the config cache exists, we can assume that the Laravel root path is the same as the Lambda task root.
        // This may not be needed as the fallback "should" work in all cases, but we're keeping
        // this as it's safer to keep 100% compatibility with the original implementation.
        if (file_exists($app)) {
            return $_SERVER['LAMBDA_TASK_ROOT'];
        }

        // If Laravel is installed on a sub-folder, we can navigate from where we are
        // (inside composer) to the root of Laravel.
        // We will go up 4 directories, represented by `vendor/brefphp/laravel-bridge/src`.
        return realpath(__DIR__ . '/../../../../');
    }

    public static function app(): string
    {
        $bootstrapFile = self::root() . '/bootstrap/app.php';

        if (file_exists($bootstrapFile)) {
            return $bootstrapFile;
        }

        throw new RuntimeException(
            "Unable to locate `{$bootstrapFile}`: Bref tried to load that file to retrieve the Laravel app"
        );
    }
}
