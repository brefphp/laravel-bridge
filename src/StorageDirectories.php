<?php

namespace CacheWerk\BrefLaravelBridge;

class StorageDirectories
{
    /**
     * The storage path for the execution environment.
     *
     * @var string
     */
    public const Path = '/tmp/storage';

    /**
     * Ensure the necessary storage directories exist.
     *
     * @return void
     */
    public static function create()
    {
        $directories = [
            // self::Path . '/app',
            // self::Path . '/logs',
            self::Path . '/bootstrap/cache',
            self::Path . '/framework/cache',
            self::Path . '/framework/views',
        ];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                fwrite(STDERR, "Creating storage directory: {$directory}" . PHP_EOL);

                mkdir($directory, 0755, true);
            }
        }
    }
}
