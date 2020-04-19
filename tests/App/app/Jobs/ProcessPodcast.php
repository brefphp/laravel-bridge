<?php declare(strict_types=1);

namespace App\Jobs;

use Bref\Test\LaravelBridge\App\app\Jobs\Middleware\JobMiddleware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPodcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    private $podcastId;

    public function __construct(int $podcastId)
    {
        $this->podcastId = $podcastId;
    }

    public function handle(): void
    {
        echo "Processing podcast {$this->podcastId}\n";
    }

    public function middleware(): array
    {
        return [
            new JobMiddleware,
        ];
    }
}
