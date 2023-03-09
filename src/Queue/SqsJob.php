<?php

namespace Bref\LaravelBridge\Queue;

use Illuminate\Queue\Jobs\SqsJob as LaravelSqsJob;

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
