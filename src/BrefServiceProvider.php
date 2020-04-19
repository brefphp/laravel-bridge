<?php declare(strict_types=1);

namespace Bref\LaravelBridge;

use Illuminate\Support\ServiceProvider;

class BrefServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SqsLaravelQueueHandler::class, function () {
            return new SqsLaravelQueueHandler();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    }
}
