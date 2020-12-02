<?php

namespace Bref\LaravelBridge\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Worker as LaravelWorker;
use Illuminate\Queue\WorkerOptions;

class Worker extends LaravelWorker
{
    public function runSqsJob(Job $job, string $connectionName, WorkerOptions $options): void
    {
        pcntl_async_signals(true);

        // pcntl_signal(SIGALRM, function () use ($job) {
        //     throw new VaporJobTimedOutException($job->resolveName());
        // });

        pcntl_alarm(
            max($this->timeoutForJob($job, $options), 0)
        );

        $this->runJob($job, $connectionName, $options);

        pcntl_alarm(0);
    }
}
