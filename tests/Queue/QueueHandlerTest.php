<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Queue;

use Aws\CommandInterface;
use Aws\Result;
use Bref\Context\Context;
use Bref\LaravelBridge\Queue\QueueHandler;
use Bref\LaravelBridge\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

class QueueHandlerTest extends TestCase
{
    /** @var CommandInterface[] */
    private array $sqsCommands = [];

    /** @var MessageLogged[] */
    private array $logs = [];

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

        // Laravel's default failed job storage, but without a database (e.g. an application using DynamoDB)
        $app['config']->set('queue.failed.driver', 'database-uuids');
        $app['config']->set('queue.failed.database', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', '/does-not-exist/database.sqlite');
    }

    public function testTheJobExceptionIsLoggedWhenTheFailedJobCannotBeStored(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $log) {
            $this->logs[] = $log;
        });

        $result = $this->runJob(new FailingJob);

        $errors = $this->errorLogs();
        // The job's own exception is reported
        $this->assertContains('Job failed on purpose', $errors);
        $this->assertContains('Job failed message-id', $errors);
        // The error storing the failed job is reported too
        $this->assertCount(1, array_filter($errors, fn (string $message) => str_contains($message, '/does-not-exist/database.sqlite')));
        // The job is not retried: its message is deleted from the queue
        $this->assertNull($result);
        $this->assertSame(['DeleteMessage'], array_map(fn (CommandInterface $command) => $command->getName(), $this->sqsCommands));
    }

    private function runJob(object $job): ?array
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

    /**
     * @return string[]
     */
    private function errorLogs(): array
    {
        $errors = array_filter($this->logs, fn (MessageLogged $log) => $log->level === 'error');

        return array_values(array_map(fn (MessageLogged $log) => $log->message, $errors));
    }
}
