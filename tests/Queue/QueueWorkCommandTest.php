<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Queue;

use Bref\LaravelBridge\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Jobs run by `php artisan queue:work` (e.g. in local development) instead of Bref's queue handler.
 */
class QueueWorkCommandTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.default', 'null');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'sqlite',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'sqlite',
            'table' => 'failed_jobs',
        ]);
    }

    public function testTheFailedJobIsStoredOnce(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $errors = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$errors) {
            if ($log->level === 'error') {
                $errors[] = $log->message;
            }
        });

        Queue::push(new FailingJob);
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertSame(1, DB::table('failed_jobs')->count());
        // Only the job's own exception is reported
        $this->assertSame(['Job failed 1', 'Job failed on purpose'], $errors);
    }
}
