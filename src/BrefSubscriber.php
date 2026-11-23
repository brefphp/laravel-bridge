<?php

declare(strict_types=1);

namespace Bref\LaravelBridge;

use Bref\Listener\BrefEventSubscriber;
use Composer\Autoload\ClassLoader;
use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Requests\GetRequest;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;
use ReflectionClass;
use Throwable;

/**
 * @internal
 */
class BrefSubscriber extends BrefEventSubscriber
{
    /**
     * Socket of the PHP-FPM process started by Bref's FPM runtime (`Bref\FpmRuntime\FpmHandler`).
     */
    private const FPM_SOCKET = '/tmp/.bref/php-fpm.sock';

    private ?string $configCachePendingFor = null;

    public function beforeStartup(): void
    {
        $laravelRoot = LaravelPathFinder::root();

        StorageDirectories::create();

        MaintenanceMode::setUp();

        // Move the location of the PsySH config cache to `/tmp` (because it is writable)
        $xdgHome = StorageDirectories::Path . '/psysh';
        $_SERVER['XDG_CONFIG_HOME'] = $_ENV['XDG_CONFIG_HOME'] = $xdgHome;
        putenv("XDG_CONFIG_HOME=$xdgHome");

        // @phpstan-ignore-next-line
        $shouldCache = env('BREF_LARAVEL_CACHE_CONFIG', env('APP_ENV') !== 'local');

        if (! $shouldCache) {
            return;
        }

        $defaultConfigCachePath = $laravelRoot . '/bootstrap/cache/config.php';

        if (file_exists($defaultConfigCachePath)) {
            return;
        }

        // Move the location of the config cache to `/tmp` (because it is writable)
        $newConfigCachePath = StorageDirectories::Path . '/bootstrap/cache/config.php';

        // Automatically caches the configuration if it does not exist (only once)
        if (! file_exists($newConfigCachePath)) {
            // Set before PHP-FPM starts, so that PHP-FPM inherits it
            $_SERVER['APP_CONFIG_CACHE'] = $_ENV['APP_CONFIG_CACHE'] = $newConfigCachePath;
            putenv("APP_CONFIG_CACHE={$newConfigCachePath}");

            if (self::isFpmRuntime()) {
                // With PHP-FPM, the configuration is cached by PHP-FPM itself once it has started
                // (see afterStartup()), still during the cold start
                $this->configCachePendingFor = $laravelRoot;
                return;
            }

            self::cacheConfigWithCli($laravelRoot);
        }
    }

    public function afterStartup(): void
    {
        if ($this->configCachePendingFor === null) {
            return;
        }
        $laravelRoot = $this->configCachePendingFor;
        $this->configCachePendingFor = null;

        if (! self::cacheConfigWithFpm($laravelRoot)) {
            self::cacheConfigWithCli($laravelRoot);
        }
    }

    private static function isFpmRuntime(): bool
    {
        $runtime = $_SERVER['BREF_RUNTIME'] ?? getenv('BREF_RUNTIME');

        return in_array($runtime, ['fpm', 'Bref\FpmRuntime\Main'], true);
    }

    private static function cacheConfigWithCli(string $laravelRoot): void
    {
        $outputDestination = '> /dev/null';
        if (!getenv('BREF_LARAVEL_OMIT_INITLOG')) {
            fwrite(STDERR, "Running 'php artisan config:cache' to cache the Laravel configuration\n");
            // 1>&2 redirects the output to STDERR to avoid messing up HTTP responses with FPM
            $outputDestination = '1>&2';
        }

        passthru("php $laravelRoot/artisan config:cache {$outputDestination}");
    }

    /**
     * Runs `artisan config:cache` in PHP-FPM rather than with the `php` CLI.
     *
     * Booting Laravel to cache the configuration compiles hundreds of PHP files. Since PHP 8.4.28
     * and 8.5.13, opcache no longer shares its file cache between the CLI and PHP-FPM (their system
     * ID differs), so files compiled by the CLI had to be compiled again by PHP-FPM on the first
     * request. Running the command in PHP-FPM compiles them once, into PHP-FPM's opcache.
     *
     * @return bool false if it failed and the configuration must be cached with the CLI instead
     */
    private static function cacheConfigWithFpm(string $laravelRoot): bool
    {
        if (!getenv('BREF_LARAVEL_OMIT_INITLOG')) {
            fwrite(STDERR, "Running 'artisan config:cache' in PHP-FPM to cache the Laravel configuration\n");
        }

        try {
            $request = new GetRequest(__DIR__ . '/fpm-config-cache.php');
            $request->setCustomVar('BREF_LARAVEL_ROOT', $laravelRoot);
            $request->setCustomVar('BREF_LARAVEL_AUTOLOADER', dirname((new ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php');
            // Laravel behaves as in `php artisan` (only for this request)
            $request->setCustomVar('APP_RUNNING_IN_CONSOLE', 'true');

            $response = (new Client)->sendRequest(new UnixDomainSocket(self::FPM_SOCKET, 1000, 30000), $request);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Could not cache the Laravel configuration in PHP-FPM, falling back to the CLI: ' . get_class($e) . ' ' . $e->getMessage() . "\n");
            return false;
        }

        if ($response->getStatusCode() !== 200 || $response->getHeaderLine('X-Bref-Exit-Code') !== '0') {
            fwrite(STDERR, "Could not cache the Laravel configuration in PHP-FPM (status {$response->getStatusCode()}), falling back to the CLI\n" . $response->getBody() . "\n");
            return false;
        }

        if (!getenv('BREF_LARAVEL_OMIT_INITLOG')) {
            fwrite(STDERR, $response->getBody());
        }

        return true;
    }
}
