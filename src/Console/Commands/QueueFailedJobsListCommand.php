<?php

namespace Bref\LaravelBridge\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Output failed queue jobs as JSON.
 *
 * Meant for external monitoring tools (e.g. Bref Cloud) — hidden from
 * `artisan list` to keep the user's command list clean.
 */
class QueueFailedJobsListCommand extends Command
{
    use FormatsFailedJob;

    protected $signature = 'bref:failed-jobs:list
                            {--limit=50 : Maximum number of jobs to return}';

    protected $description = 'Output failed queue jobs as JSON.';

    protected $hidden = true;

    public function handle(FailedJobProviderInterface $failer): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $all = $failer->all();
        $jobs = array_slice($all, 0, $limit);

        $this->writeJson([
            'total' => count($all),
            'returned' => count($jobs),
            'jobs' => array_map($this->format(...), $jobs),
        ]);

        return self::SUCCESS;
    }
}
