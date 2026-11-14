<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Queue;

use Aws\CommandInterface;
use Aws\Result;
use Bref\Context\Context;
use Bref\LaravelBridge\Queue\QueueHandler;
use Bref\LaravelBridge\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Queue;

abstract class QueueTestCase extends TestCase
{
    /** @var CommandInterface[] */
    protected array $sqsCommands = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.default', 'null');
        $app['config']->set('queue.default', 'sqs');
        $app['config']->set('queue.connections.sqs', [
            'driver' => 'sqs',
            'key' => 'key',
            'secret' => 'secret',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789012',
            'queue' => 'default',
            'region' => 'us-east-1',
            // Record the SQS API calls instead of sending them
            'handler' => function (CommandInterface $command) {
                $this->sqsCommands[] = $command;
                return Create::promiseFor(new Result(['MessageId' => 'message-id']));
            },
        ]);
    }

    protected function runJob(object $job): ?array
    {
        Queue::connection('sqs')->push($job);
        $body = $this->sqsCommands[0]['MessageBody'];
        $this->sqsCommands = [];

        $handler = $this->app->makeWith(QueueHandler::class, ['connection' => 'sqs']);

        return $handler->handle([
            'Records' => [
                [
                    'messageId' => 'message-id',
                    'receiptHandle' => 'receipt-handle',
                    'body' => $body,
                    'attributes' => ['ApproximateReceiveCount' => '1'],
                    'messageAttributes' => [],
                    'eventSource' => 'aws:sqs',
                    'eventSourceARN' => 'arn:aws:sqs:us-east-1:123456789012:default',
                    'awsRegion' => 'us-east-1',
                ],
            ],
        ], new Context('request-id', (int) (microtime(true) * 1000) + 10_000, 'function-arn', 'trace-id'));
    }
}
