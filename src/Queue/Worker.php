<?php declare(strict_types=1);

namespace Bref\LaravelBridge\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Worker as LaravelWorker;
use Illuminate\Queue\WorkerOptions;

class Worker extends LaravelWorker
{
    public function runSqsJob(Job $job, string $connectionName, WorkerOptions $options): void
    {
        $this->runJob($job, $connectionName, $options);
    }
}
