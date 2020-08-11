<?php declare(strict_types=1);

namespace Bref\LaravelBridge;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class BrefServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Make sure the directory for compiled views exist
        $compiledViewDirectory = Config::get('view.compiled');
        if (! is_dir($compiledViewDirectory)) {
            // The directory doesn't exist: let's create it, else Laravel will not create it automatically
            // and will fail with an error
            if (! mkdir($compiledViewDirectory, 0755, true) && ! is_dir($compiledViewDirectory)) {
                throw new RuntimeException(sprintf('Directory "%s" cannot be created', $compiledViewDirectory));
            }
        }

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
        // (if it hasn't been changed by the user)
        $logDriver = Config::get('logging.default');
        if ($logDriver === 'stack' && ! isset($_SERVER['LOG_CHANNEL'])) {
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
    }
}
