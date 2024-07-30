<?php

namespace Bref\LaravelBridge;

use Bref\LaravelBridge\Queue\QueueHandler;

use Illuminate\Console\Events\ScheduledTaskStarting;

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

        $this->app->useStoragePath(StorageDirectories::Path);

        $this->fixDefaultConfiguration();

        Config::set('app.mix_url', Config::get('app.asset_url'));

        Config::set('trustedproxy.proxies', ['0.0.0.0/0', '2000:0:0:0:0:0:0:0/3']);

        Config::set('view.compiled', StorageDirectories::Path . '/framework/views');
        Config::set('cache.stores.file.path', StorageDirectories::Path . '/framework/cache');

        $this->fixAwsCredentialsConfig();

        $this->app->when(QueueHandler::class)
            ->needs('$connection')
            ->giveConfig('queue.default');
    }

    /**
     * Bootstrap package services.
     *
     * @return void
     */
    public function boot(Dispatcher $dispatcher, LogManager $logManager, FailedJobProviderInterface $queueFailer)
    {
        $this->app[Kernel::class]->pushMiddleware(Http\Middleware\ServeStaticAssets::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../stubs/serverless.yml' => base_path('serverless.yml'),
            ], 'serverless-config');

            $this->publishes([
                __DIR__ . '/../config/bref.php' => config_path('bref.php'),
            ], 'bref-config');
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
            fn (JobExceptionOccurred $event) => $logManager->error(
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

        if (file_exists('/proc/1/fd/1')) {
            $dispatcher->listen(
                ScheduledTaskStarting::class,
                fn(ScheduledTaskStarting $task) => $task->task->appendOutputTo('/proc/1/fd/1'),
            );
        }
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

        if (Config::get('logging.default') === 'stack') {
            Config::set('logging.default', 'stderr');
        }
    }

    private function fixAwsCredentialsConfig(): void
    {
        $accessKeyId = $_SERVER['AWS_ACCESS_KEY_ID'] ?? null;
        $sessionToken = $_SERVER['AWS_SESSION_TOKEN'] ?? null;
        // If we are not in a Lambda environment, we don't need to do anything
        if (!$accessKeyId || ! $sessionToken) {
            return;
        }

        // Patch SQS config
        foreach (Config::get('queue.connections') as $name => $connection) {
            if ($connection['driver'] !== 'sqs') {
                continue;
            }
            // If a different key is in the config than in the environment variables
            if ($connection['key'] && $connection['key'] !== $accessKeyId) {
                continue;
            }

            Config::set("queue.connections.$name.token", $sessionToken);
        }

        // Patch S3 config
        foreach (Config::get('filesystems.disks') as $name => $disk) {
            if ($disk['driver'] !== 's3') {
                continue;
            }
            // If a different key is in the config than in the environment variables
            if ($disk['key'] && $disk['key'] !== $accessKeyId) {
                continue;
            }

            Config::set("filesystems.disks.$name.token", $sessionToken);
        }

        // Patch DynamoDB config
        foreach (Config::get('cache.stores') as $name => $store) {
            if ($store['driver'] !== 'dynamodb') {
                continue;
            }
            // If a different key is in the config than in the environment variables
            if ($store['key'] && $store['key'] !== $accessKeyId) {
                continue;
            }

            Config::set("cache.stores.$name.token", $sessionToken);
        }

        // Patch SES config
        if (Config::get('services.ses.key') === $accessKeyId) {
            Config::set('services.ses.token', $sessionToken);
        }
    }
}
