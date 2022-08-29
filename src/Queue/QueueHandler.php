<?php

namespace CacheWerk\BrefLaravelBridge\Queue;

use Throwable;
use RuntimeException;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;

use Illuminate\Log\LogManager;

use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\Jobs\SqsJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobExceptionOccurred;

class QueueHandler extends SqsHandler
{
    /**
     * Creates a new SQS queue handler instance.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @param  \Illuminate\Contracts\Debug\ExceptionHandler  $exceptions
     * @param  string  $connection
     * @param  string  $queue
     * @return void
     */
    public function __construct(
        protected Container $container,
        protected Dispatcher $events,
        protected ExceptionHandler $exceptions,
        protected string $connection,
        protected string $queue,
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
        foreach ($event->getRecords() as $sqsRecord) {
            $recordData = $sqsRecord->toArray();

            $jobData = [
                'MessageId' => $recordData['messageId'],
                'ReceiptHandle' => $recordData['receiptHandle'],
                'Attributes' => $recordData['attributes'],
                'Body' => $recordData['body'],
            ];

            $job = new SqsJob(
                $this->container,
                $this->sqs,
                $jobData,
                $this->connection,
                $this->queue,
            );

            $this->process($this->connection, $job);
        }
    }

    /**
     * @see \Illuminate\Queue\Worker::process()
     */
    protected function process(string $connectionName, SqsJob $job): void
    {
        try {
            $this->raiseBeforeJobEvent($connectionName, $job);

            $job->fire();

            $this->raiseAfterJobEvent($connectionName, $job);
        } catch (Throwable $exception) {
            $this->raiseExceptionOccurredJobEvent($connectionName, $job, $exception);

            $this->exceptions->report($exception);

            throw $exception;
        }
    }

    /**
     * @see \Illuminate\Queue\Worker::raiseBeforeJobEvent()
     */
    protected function raiseBeforeJobEvent(string $connectionName, SqsJob $job): void
    {
        $this->container->make(LogManager::class)
            ->info("Processing job {$job->getJobId()}", ['name' => $job->resolveName()]);

        $this->events->dispatch(new JobProcessing($connectionName, $job));
    }

    /**
     * @see \Illuminate\Queue\Worker::raiseAfterJobEvent()
     */
    protected function raiseAfterJobEvent(string $connectionName, SqsJob $job): void
    {
        $this->container->make(LogManager::class)
            ->info("Processed job {$job->getJobId()}", ['name' => $job->resolveName()]);

        $this->events->dispatch(new JobProcessed($connectionName, $job));
    }

    /**
     * @see \Illuminate\Queue\Worker::raiseExceptionOccurredJobEvent()
     */
    protected function raiseExceptionOccurredJobEvent(string $connectionName, SqsJob $job, Throwable $th): void
    {
        $this->container->make(LogManager::class)
            ->error("Job failed {$job->getJobId()}", ['name' => $job->resolveName()]);

        $this->events->dispatch(new JobExceptionOccurred($connectionName, $job, $th));
    }
}
