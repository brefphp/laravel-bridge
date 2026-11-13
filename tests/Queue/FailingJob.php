<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

class FailingJob implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('Job failed on purpose');
    }
}
