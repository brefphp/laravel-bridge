<?php

namespace CacheWerk\BrefLaravelBridge;

use Monolog\Formatter\JsonFormatter;

use Illuminate\Log\LogManager;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Events\Dispatcher;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

class BrefServiceProvider extends ServiceProvider
{
    /**
     * Set up Bref integration.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bref.php', 'bref');
        $this->shareRequestContext();

        if (! isset($_SERVER['LAMBDA_TASK_ROOT'])) {
            return;
        }

        $this->app[Kernel::class]->pushMiddleware(Http\Middleware\ServeStaticAssets::class);

        $this->fixDefaultConfiguration();

        Config::set('app.mix_url', Config::get('app.asset_url'));

        Config::set('logging.channels.stderr.formatter', JsonFormatter::class);

        Config::set('trustedproxy.proxies', ['0.0.0.0/0', '2000:0:0:0:0:0:0:0/3']);

        Config::set('view.compiled', StorageDirectories::Path . '/framework/views');
        Config::set('cache.stores.file.path', StorageDirectories::Path . '/framework/cache');

        Config::set('cache.stores.dynamodb.key');
        Config::set('cache.stores.dynamodb.secret');
        Config::set('cache.stores.dynamodb.token', env('AWS_SESSION_TOKEN'));

        Config::set('filesystems.disks.s3.key');
        Config::set('filesystems.disks.s3.secret');
        Config::set('filesystems.disks.s3.token', env('AWS_SESSION_TOKEN'));

        $account = env('AWS_ACCOUNT_ID');
        $region = env('AWS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1'));

        Config::set('queue.connections.sqs.key');
        Config::set('queue.connections.sqs.secret');
        Config::set('queue.connections.sqs.token', env('AWS_SESSION_TOKEN'));
        Config::set('queue.connections.sqs.prefix', env('SQS_PREFIX', "https://sqs.{$region}.amazonaws.com/{$account}"));
    }

    /**
     * Bootstrap package services.
     *
     * @return void
     */
    public function boot(Dispatcher $dispatcher, LogManager $logManager, FailedJobProviderInterface $queueFailer)
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/bref.php' => config_path('bref.php'),
            ], 'bref-config');

            $this->publishes([
                __DIR__ . '/../stubs/runtime.php' => base_path('php/runtime.php'),
            ], 'bref-runtime');
        }

        $dispatcher->listen(
            fn (JobProcessing $event) => $logManager->info(
                "Processing job {$event->job->getJobId()}",
                ['name' => $event->job->resolveName()]
            )
        );

        $dispatcher->listen(
            fn (JobProcessed $event) => $logManager->info(
                "Processed job {$event->job->getJobId()}",
                ['name' => $event->job->resolveName()]
            )
        );

        $dispatcher->listen(
            fn (JobExceptionOccurred $event) => $logManager->info(
                "Job failed {$event->job->getJobId()}",
                ['name' => $event->job->resolveName()]
            )
        );

        $dispatcher->listen(
            fn (JobFailed $event) => $queueFailer->log(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getRawBody(),
                $event->exception
            )
        );
    }

    /**
     * Add the request identifier to the shared log context.
     *
     * @return void
     */
    protected function shareRequestContext()
    {
        if (! Config::get('bref.request_context')) {
            return;
        }

        $this->app->rebinding('request', function ($app, $request) {
            if ($request->hasHeader('X-Request-ID')) {
                $app->make(LogManager::class)->shareContext([
                    'requestId' => $request->header('X-Request-ID'),
                ]);
            }
        });
    }

    /**
     * Prevent the default Laravel configuration from causing errors.
     *
     * @return void
     */
    protected function fixDefaultConfiguration()
    {
        if (Config::get('session.driver') === 'file') {
            Config::set('session.driver', 'cookie');
        }

        if (Config::get('filesystems.default') === 'local') {
            Config::set('filesystems.default', 's3');
        }

        if (Config::get('logging.default') === 'stack') {
            Config::set('logging.default', 'stderr');
        }
    }
}
