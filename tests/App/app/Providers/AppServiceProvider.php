<?php

namespace App\Providers;

use Bref\Test\LaravelBridge\FakeSqsConnector;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->extend('queue', function (QueueManager $manager) {
            $manager->addConnector('fake-sqs', function () {
                return new FakeSqsConnector;
            });
            return $manager;
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
