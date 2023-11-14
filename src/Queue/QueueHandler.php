<?php

namespace Bref\LaravelBridge\Queue;

use RuntimeException;

use Aws\Sqs\SqsClient;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use Bref\Event\Sqs\SqsRecord;

use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Cache\Repository as Cache;

use Bref\LaravelBridge\MaintenanceMode;

class QueueHandler extends SqsHandler
{
    /**
     * The AWS SQS client.
     *
     * @var \Aws\Sqs\SqsClient
     */
    protected SqsClient $sqs;

    /**
     * Number of seconds before Lambda invocation deadline to timeout the job.
     *
     * @var float
     */
    protected const JOB_TIMEOUT_SAFETY_MARGIN = 1.0;

    /**
     * Creates a new SQS queue handler instance.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @param  \Illuminate\Contracts\Debug\ExceptionHandler  $exceptions
     * @param  string  $connection
     * @return void
     */
    public function __construct(
        protected Container $container,
        protected Dispatcher $events,
        protected ExceptionHandler $exceptions,
        protected string $connection,
    ) {
        $queue = $container->make(QueueManager::class)
            ->connection($connection);

        if (! $queue instanceof SqsQueue) {
            throw new RuntimeException('Default queue connection is not a SQS connection');
        }

        $this->sqs = $queue->getSqs();
    }

    /**
     * Handle Bref SQS event.
     *
     * @param  \Bref\Event\Sqs\SqsEvent  $event
     * @param  \Bref\Context\Context  $context
     * @return void
     */
    public function handleSqs(SqsEvent $event, Context $context): void
    {
        /** @var Worker $worker */
        $worker = $this->container->makeWith(Worker::class, [
            'isDownForMaintenance' => fn () => MaintenanceMode::active(),
        ]);

        $worker->setCache(
            $this->container->make(Cache::class)
        );

        foreach ($event->getRecords() as $sqsRecord) {
            $timeout = $this->calculateJobTimeout($context->getRemainingTimeInMillis());

            $worker->runSqsJob(
                $job = $this->marshalJob($sqsRecord),
                $this->connection,
                $this->gatherWorkerOptions($timeout),
            );

            if (! $job->hasFailed() && ! $job->isDeleted()) {
                $job->delete();
            }
        }
    }

    /**
     * Marshal the job with the given Bref SQS record.
     *
     * @param  \Bref\Event\Sqs\SqsRecord  $sqsRecord
     * @return \Bref\LaravelBridge\Queue\SqsJob
     */
    protected function marshalJob(SqsRecord $sqsRecord): SqsJob
    {
        $message = [
            'MessageId' => $sqsRecord->getMessageId(),
            'ReceiptHandle' => $sqsRecord->getReceiptHandle(),
            'Body' => $sqsRecord->getBody(),
            'Attributes' => $sqsRecord->toArray()['attributes'],
            'MessageAttributes' => $sqsRecord->getMessageAttributes(),
        ];

        return new SqsJob(
            $this->container,
            $this->sqs,
            $message,
            $this->connection,
            $sqsRecord->getQueueName(),
        );
    }

    /**
     * Gather all of the queue worker options as a single object.
     *
     * @param  int  $timeout
     * @return \Illuminate\Queue\WorkerOptions
     */
    protected function gatherWorkerOptions(int $timeout): WorkerOptions
    {
        $options = [
            0, // backoff
            512, // memory
            $timeout, // timeout
            0, // sleep
            3, // maxTries
            false, // force
            false, // stopWhenEmpty
            0, // maxJobs
            0, // maxTime
        ];

        if (property_exists(WorkerOptions::class, 'name')) {
            $options = array_merge(['default'], $options);
        }

        return new WorkerOptions(...$options);
    }

    /**
     * Calculate the timeout for a job
     *
     * @param  int  $remainingInvocationTimeInMs
     * @return int
     */
    protected function calculateJobTimeout(int $remainingInvocationTimeInMs): int
    {
        return max((int) (($remainingInvocationTimeInMs - self::JOB_TIMEOUT_SAFETY_MARGIN) / 1000), 0);
    }
}
