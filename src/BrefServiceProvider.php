<?php declare(strict_types=1);

namespace Bref\LaravelBridge;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class BrefServiceProvider extends ServiceProvider
{
    private function ensureDirectoryExists(string $path): void
    {
        if (! is_dir($path)) {
            if (! mkdir($path, 0755, true) && ! is_dir($path)) {
                throw new RuntimeException(sprintf('Directory "%s" cannot be created', $path));
            }
        }
    }

    public function boot(): void
    {
        // Laravel will not create those directories automatically and will fail with an error.
        // So let's make sure the directories for compiled views and Real-Time Facades exist
        $this->ensureDirectoryExists(Config::get('view.compiled'));
        $this->ensureDirectoryExists('/tmp/storage/framework/cache');

        $this->publishes([
            __DIR__ . '/../config/serverless.yml' => $this->app->basePath('serverless.yml'),
        ], 'serverless-config');
    }

    public function register(): void
    {
        $isRunningInLambda = isset($_SERVER['LAMBDA_TASK_ROOT']);

        // Laravel Mix URL for assets stored on S3
        $mixAssetUrl = $_SERVER['MIX_ASSET_URL'] ?? null;
        if ($mixAssetUrl) {
            Config::set('app.mix_url', $mixAssetUrl);
        }

        // The rest below is specific to AWS Lambda
        if (! $isRunningInLambda) {
            return;
        }

        // We change Laravel's default log destination to stderr
        $logDriver = Config::get('logging.default');
        if ($logDriver === 'stack') {
            Config::set('logging.default', 'stderr');
        }

        // Store compiled views in `/tmp` because they are generated at runtime
        // and `/tmp` is the only writable directory on Lambda
        Config::set('view.compiled', '/tmp/storage/framework/views');

        // Allow all proxies because AWS Lambda runs behind API Gateway
        // See https://github.com/fideloper/TrustedProxy/issues/115#issuecomment-503596621
        Config::set('trustedproxy.proxies', ['0.0.0.0/0', '2000:0:0:0:0:0:0:0/3']);

        // Sessions cannot be stored to files, so we use cookies by default instead
        $sessionDriver = Config::get('session.driver');
        if ($sessionDriver === 'file') {
            Config::set('session.driver', 'cookie');
        }

        // The native Laravel storage directory is read-only, we move the cache to /tmp
        // to avoid errors. If you want to actively use the cache, it will be best to use
        // the dynamodb driver instead.
        Config::set('cache.stores.file.path', '/tmp/storage/framework/cache');

        // This is the official solution for changing the cached Real-Time Facades path
        // See: https://github.com/laravel/framework/issues/33839#issuecomment-673118699
        $this->app->useStoragePath('/tmp/storage');
    }
}
