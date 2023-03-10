<?php

namespace Bref\LaravelBridge\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Queue\Worker as LaravelWorker;
use Throwable;

class Worker extends LaravelWorker
{
    /**
     * Creates a new SQS queue handler instance.
     *
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @param  string  $connectionName
     * @param  \Illuminate\Queue\WorkerOptions  $options
     * @return void
     */
    public function runSqsJob(Job $job, string $connectionName, WorkerOptions $options): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGALRM, function () use ($job) {
            $this->markJobAsFailedIfItShouldFailOnTimeout(
                $job->getConnectionName(),
                $job,
                $this->maxAttemptsExceededException($job),
            );

            // exit so that PHP will shutdown and close DB connections etc.
            exit(1);
        });

        pcntl_alarm(
            max($this->timeoutForJob($job, $options), 0)
        );

        $this->runJob($job, $connectionName, $options);

        pcntl_alarm(0); // cancel the previous alarm
    }

    /**
     * Mark the given job as failed if it should fail on timeouts.
     *
     * @param  string  $connectionName
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @param  \Throwable  $e
     * @return void
     */
    protected function markJobAsFailedIfItShouldFailOnTimeout($connectionName, $job, Throwable $e)
    {
        if (method_exists($job, 'shouldFailOnTimeout') ? $job->shouldFailOnTimeout() : true) {
            $this->failJob($job, $e);
        }
    }
}
