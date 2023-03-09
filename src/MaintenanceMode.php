<?php

namespace Bref\LaravelBridge;

use Illuminate\Http\Request;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class MaintenanceMode
{
    /**
     * Toggle the application's maintenance mode.
     *
     * @return void
     */
    public static function setUp(): void
    {
        $file = StorageDirectories::Path . '/framework/down';

        if (static::active()) {
            ! file_exists($file) && file_put_contents($file, '[]');
        } else {
            file_exists($file) && unlink($file);
        }
    }

    /**
     * Determine if the application is currently down for maintenance.
     *
     * @return bool
     */
    public static function active(): bool
    {
        return ! empty($_ENV['MAINTENANCE_MODE']);
    }

    /**
     * Returns the maintenance mode response.
     *
     * @param \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function response(Request $request)
    {
        if ($request->wantsJson()) {
            $file = file_exists($_ENV['LAMBDA_TASK_ROOT'] . '/php/503.json')
                ? $_ENV['LAMBDA_TASK_ROOT'] . '/php/503.json'
                : realpath(__DIR__ . '/../stubs/503.json');

            return JsonResponse::fromJsonString(file_get_contents($file), 503);
        }

        $file = file_exists($_ENV['LAMBDA_TASK_ROOT'] . '/php/503.html')
            ? $_ENV['LAMBDA_TASK_ROOT'] . '/php/503.html'
            : realpath(__DIR__ . '/../stubs/503.html');

        return new Response(file_get_contents($file), 503);
    }
}
