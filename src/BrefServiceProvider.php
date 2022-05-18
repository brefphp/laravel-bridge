<?php

namespace CacheWerk\BrefLaravelBridge;

use Monolog\Formatter\JsonFormatter;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class BrefServiceProvider extends ServiceProvider
{
    /**
     * Set up Bref integration.
     *
     * @return void
     */
    public function register()
    {
        if (! isset($_SERVER['LAMBDA_TASK_ROOT'])) {
            return;
        }

        $account = env('AWS_ACCOUNT_ID');
        $region = env('AWS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1'));

        Config::set('app.mix_url', Config::get('app.asset_url'));
        Config::set('view.compiled', StorageDirectories::Path . '/framework/views');
        Config::set('logging.channels.stderr.formatter', JsonFormatter::class);
        Config::set('trustedproxy.proxies', ['0.0.0.0/0', '2000:0:0:0:0:0:0:0/3']);

        Config::set('cache.stores.file.path', StorageDirectories::Path . '/framework/cache');

        Config::set('cache.stores.dynamodb.key');
        Config::set('cache.stores.dynamodb.secret');
        Config::set('cache.stores.dynamodb.token', env('AWS_SESSION_TOKEN'));

        Config::set('queue.connections.sqs.key');
        Config::set('queue.connections.sqs.secret');
        Config::set('queue.connections.sqs.token', env('AWS_SESSION_TOKEN'));
        Config::set('queue.connections.sqs.prefix', env('SQS_PREFIX', "https://sqs.{$region}.amazonaws.com/{$account}"));

        $this->app[Kernel::class]->pushMiddleware(Http\Middleware\ServeStaticAssets::class);
    }

    /**
     * Bootstrap package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../stubs/runtime.php' => base_path('php/runtime.php'),
        ], 'bref-runtime');
    }
}
