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

        // If the config cache exists, we can assume that the Laravel root path is the same as the Lambda task root
        if (file_exists($app)) {
            return $_SERVER['LAMBDA_TASK_ROOT'];
        }

        // the fallback is going up 4 directories will get us from `vendor/brefphp/laravel-bridge/src` to the Laravel root folder
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
