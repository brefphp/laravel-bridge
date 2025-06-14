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
use Illuminate\Support\Facades\Facade;

use Bref\LaravelBridge\MaintenanceMode;

class QueueHandler extends SqsHandler
{
    protected SqsClient $sqs;

    /**
     * Number of seconds before Lambda invocation deadline to timeout the job.
     */
    protected const JOB_TIMEOUT_SAFETY_MARGIN = 1.0;

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
     * Handle SQS event.
     */
    public function handleSqs(SqsEvent $event, Context $context): void
    {
        /** @var Worker $worker */
        $worker = $this->container->makeWith(Worker::class, [
            'isDownForMaintenance' => fn () => MaintenanceMode::active(),
            'resetScope' => fn() => $this->resetLaravel(),
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
     */
    protected function calculateJobTimeout(int $remainingInvocationTimeInMs): int
    {
        return max((int) (($remainingInvocationTimeInMs - self::JOB_TIMEOUT_SAFETY_MARGIN) / 1000), 0);
    }

    /**
     * Called on each new job to reset Laravel between jobs.
     */
    private function resetLaravel(): void
    {
        // @phpstan-ignore-next-line
        if (method_exists($this->container['log'], 'flushSharedContext')) {
            $this->container['log']->flushSharedContext();
        }

        // @phpstan-ignore-next-line
        if (method_exists($this->container['log'], 'withoutContext')) {
            $this->container['log']->withoutContext();
        }

        // @phpstan-ignore-next-line
        if (method_exists($this->container['db'], 'getConnections')) {
            foreach ($this->container['db']->getConnections() as $connection) {
                $connection->resetTotalQueryDuration();
                $connection->allowQueryDurationHandlersToRunAgain();
            }
        }

        $this->container->forgetScopedInstances();

        Facade::clearResolvedInstances();
    }
}
