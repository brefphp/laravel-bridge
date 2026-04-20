<?php

namespace Bref\LaravelBridge\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Output the number of failed queue jobs as JSON.
 *
 * Meant for external monitoring tools (e.g. Bref Cloud) — hidden from
 * `artisan list` to keep the user's command list clean.
 */
class QueueFailedJobsCountCommand extends Command
{
    use FormatsFailedJob;

    protected $name = 'bref:failed-jobs:count';

    protected $description = 'Output the number of failed queue jobs as JSON.';

    protected $hidden = true;

    public function handle(FailedJobProviderInterface $failer): int
    {
        $count = $failer instanceof CountableFailedJobProvider
            ? $failer->count()
            : count($failer->all());

        $this->writeJson(['total' => $count]);

        return self::SUCCESS;
    }
}
