<?php

namespace Bref\LaravelBridge;

class LaravelRootResolver
{
    /**
     * Resolve the Laravel root path.
     *
     * @return string
     */
    public static function resolvePath(): string
    {
        $laravelHome = $_SERVER['LAMBDA_TASK_ROOT'] . '/bootstrap/cache/config.php';

        // If the config cache exists, we can assume that the Laravel root path is the same as the Lambda task root
        if (file_exists($laravelHome)) {
            return $_SERVER['LAMBDA_TASK_ROOT'];
        }

        // the fallback is going up 4 directories will get us from `vendor/brefphp/laravel-bridge/src` to the Laravel root folder
        return realpath(__DIR__ . '/../../../../') . '/';
    }
}
