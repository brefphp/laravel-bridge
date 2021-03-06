<?php declare(strict_types=1);

namespace Bref\LaravelBridge\Queue;

use Aws\Sqs\SqsClient;
use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use Illuminate\Container\Container;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\WorkerOptions;

/**
 * SQS handler for AWS Lambda that integrates with Laravel Queue.
 */
class LaravelSqsHandler extends SqsHandler
{
    /** @var Container $container */
    private $container;

    /** @var SqsClient */
    private $sqs;

    /** @var string */
    private $connectionName;

    /** @var string */
    private $queue;

    public function __construct(Container $container, string $connection, string $queue)
    {
        $this->container = $container;
        $queueManager = $container->get('queue');
        \assert($queueManager instanceof QueueManager);
        $queueConnector = $queueManager->connection($connection);
        if (! $queueConnector instanceof SqsQueue) {
            throw new \RuntimeException("The '$connection' connection is not a SQS connection in the Laravel config");
        }
        $this->sqs = $queueConnector->getSqs();
        $this->connectionName = $connection;
        $this->queue = $queue;
    }

    public function handleSqs(SqsEvent $event, Context $context): void
    {
        $worker = $this->container->makeWith(Worker::class, [
            'isDownForMaintenance' => function () {
                return false;
            },
        ]);

        \assert($worker instanceof Worker);

        foreach ($event->getRecords() as $sqsRecord) {
            $message = $this->normalizeMessage($sqsRecord->toArray());

            $worker->runSqsJob(
                $this->buildJob($message),
                $this->connectionName,
                $this->getWorkerOptions()
            );
        }
    }

    protected function normalizeMessage(array $message): array
    {
        return [
            'MessageId' => $message['messageId'],
            'ReceiptHandle' => $message['receiptHandle'],
            'Body' => $message['body'],
            'Attributes' => $message['attributes'],
            'MessageAttributes' => $message['messageAttributes'],
        ];
    }

    protected function buildJob(array $message): SqsJob
    {
        return new SqsJob(
            $this->container,
            $this->sqs,
            $message,
            $this->connectionName,
            $this->queue,
        );
    }

    protected function getWorkerOptions(): WorkerOptions
    {
        $options = [
            $backoff = 0,
            $memory = 512,
            $timeout = 0,
            $sleep = 0,
            $maxTries = 3,
            $force = false,
            $stopWhenEmpty = false,
            $maxJobs = 0,
            $maxTime = 0,
        ];

        if (property_exists(WorkerOptions::class, 'name')) {
            $options = array_merge(['default'], $options);
        }

        return new WorkerOptions(...$options);
    }
}
