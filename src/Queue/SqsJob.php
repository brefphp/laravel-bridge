<?php

namespace Bref\LaravelBridge\Queue;

use Illuminate\Queue\Jobs\SqsJob as LaravelSqsJob;
use Illuminate\Support\Str;

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

        $sqsMessage = [
            'QueueUrl' => $this->queue,
            'MessageBody' => json_encode($payload),
            'DelaySeconds' => $this->secondsUntil($delay)
        ];

        if (Str::endsWith($this->queue, '.fifo')) {
            $sqsMessage['MessageGroupId'] = $this->job['Attributes']['MessageGroupId'];
            $sqsMessage['MessageDeduplicationId'] = $this->parseDeduplicationId($payload['attempts']);
            unset($sqsMessage["DelaySeconds"]);
        }

        $this->sqs->sendMessage($sqsMessage);
    }

    /**
     * {@inheritDoc}
     */
    public function attempts()
    {
        return ($this->payload()['attempts'] ?? 0) + 1;
    }

    /**
     * Create new MessageDeduplicationId
     * appending attempt at the end so the message will not be ignored
     *
     * https://docs.aws.amazon.com/AWSSimpleQueueService/latest/APIReference/API_SendMessage.html#API_SendMessage_RequestSyntax
     */
    private function parseDeduplicationId($attempts)
    {
        return $this->job['Attributes']['MessageDeduplicationId'] . '-' . $attempts;
    }
}
