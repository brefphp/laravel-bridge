<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Queue;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FailedJobsWithoutJobLogsTest extends QueueTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('bref.log_jobs', false);
        $app['config']->set('queue.failed.driver', 'database-uuids');
        $app['config']->set('queue.failed.database', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }

    public function testFailedJobsAreStoredWhenJobLogsAreDisabled(): void
    {
        Schema::connection('sqlite')->create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $this->runJob(new FailingJob);

        $failedJobs = $this->app['queue.failer']->all();
        $this->assertCount(1, $failedJobs);
        $this->assertSame('sqs', $failedJobs[0]->connection);
        $this->assertStringContainsString('Job failed on purpose', $failedJobs[0]->exception);
    }
}
