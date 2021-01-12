<?php declare(strict_types=1);

namespace Bref\LaravelBridge\Queue;

use Illuminate\Queue\Jobs\SqsJob as LaravelSqsJob;

/**
 * @author Taylor Otwell <taylor@laravel.com>
 * @copyright Copyright (c) 2019, Taylor Otwell
 */
class SqsJob extends LaravelSqsJob
{
    /**
     * {@inheritDoc}
     */
    public function release($delay = 0)
    {
        $this->released = true;

        $payload = $this->payload();

        $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;

        $this->sqs->deleteMessage([
            'QueueUrl' => $this->queue,
            'ReceiptHandle' => $this->job['ReceiptHandle'],
        ]);

        $this->sqs->sendMessage([
            'QueueUrl' => $this->queue,
            'MessageBody' => json_encode($payload),
            'DelaySeconds' => $this->secondsUntil($delay),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function attempts()
    {
        return ($this->payload()['attempts'] ?? 0) + 1;
    }
}
