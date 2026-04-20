<?php

namespace Bref\LaravelBridge\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Output a single failed queue job (by ID) as JSON.
 *
 * Meant for external monitoring tools (e.g. Bref Cloud) — hidden from
 * `artisan list` to keep the user's command list clean.
 */
class QueueFailedJobsShowCommand extends Command
{
    use FormatsFailedJob;

    protected $signature = 'bref:failed-jobs:show {id : The failed job ID}';

    protected $description = 'Output a single failed queue job as JSON.';

    protected $hidden = true;

    public function handle(FailedJobProviderInterface $failer): int
    {
        $job = $failer->find($this->argument('id'));
        $this->writeJson($job ? $this->format($job) : null);

        return $job ? self::SUCCESS : self::FAILURE;
    }
}
