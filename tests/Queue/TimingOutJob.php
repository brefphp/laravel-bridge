<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

class TimingOutJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 1;

    public $failOnTimeout = true;

    public function __construct(public string $failureFile)
    {
    }

    public function handle(): void
    {
        sleep(5);
    }

    public function failed(Throwable $exception): void
    {
        file_put_contents($this->failureFile, get_class($exception));
    }
}
