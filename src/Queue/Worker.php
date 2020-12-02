<?php

namespace Bref\LaravelBridge\Queue;

use Illuminate\Queue\Worker as LaravelWorker;
use Illuminate\Queue\WorkerOptions;

class Worker extends LaravelWorker
{
    /**
     * Process the given job.
     *
     * @param \Illuminate\Contracts\Queue\Job $job
     * @param string $connectionName
     * @param \Illuminate\Queue\WorkerOptions $options
     * @return void
     */
    public function runVaporJob($job, $connectionName, WorkerOptions $options)
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
