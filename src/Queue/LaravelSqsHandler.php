<?php declare(strict_types=1);

namespace Bref\LaravelBridge\Queue;

use Aws\Sqs\SqsClient;
use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SqsJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SqsQueue;
use Throwable;

/**
 * SQS handler for AWS Lambda that integrates with Laravel Queue.
 */
class LaravelSqsHandler extends SqsHandler
{
    /** @var Container */
    private $container;
    /** @var SqsClient */
    private $sqs;
    /** @var Dispatcher */
    private $events;
    /** @var ExceptionHandler */
    private $exceptions;
    /** @var string */
    private $connectionName;
    /** @var string */
    private $queue;

    public function __construct(Container $container, Dispatcher $events, ExceptionHandler $exceptions, string $connection, string $queue)
    {
        $this->container = $container;
        $queueManager = $container->get('queue');
        \assert($queueManager instanceof QueueManager);
        $queueConnector = $queueManager->connection($connection);
        if (! $queueConnector instanceof SqsQueue) {
            throw new \RuntimeException("The '$connection' connection is not a SQS connection in the Laravel config");
        }
        $this->sqs = $queueConnector->getSqs();
        $this->events = $events;
        $this->exceptions = $exceptions;
        $this->connectionName = $connection;
        $this->queue = $queue;
    }

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
                $this->connectionName,
                $this->queue,
            );

            $this->process($this->connectionName, $job);
        }
    }

    /**
     * @see \Illuminate\Queue\Worker::process()
     */
    private function process(string $connectionName, SqsJob $job): void
    {
        try {
            // First we will raise the before job event and determine if the job has already ran
            // over its maximum attempt limits, which could primarily happen when this job is
            // continually timing out and not actually throwing any exceptions from itself.
            $this->raiseBeforeJobEvent($connectionName, $job);

            // Here we will fire off the job and let it process. We will catch any exceptions so
            // they can be reported to the developers logs, etc. Once the job is finished the
            // proper events will be fired to let any listeners know this job has finished.
            $job->fire();

            $this->raiseAfterJobEvent($connectionName, $job);
        } catch (Throwable $e) {
            // Fire off an exception event
            $this->raiseExceptionOccurredJobEvent($connectionName, $job, $e);

            // Report exception to defined log channel
            $this->exceptions->report($e);

            // Rethrow the exception to let SQS handle it
            throw $e;
        }
    }

    /**
     * @see \Illuminate\Queue\Worker::raiseBeforeJobEvent()
     */
    private function raiseBeforeJobEvent(string $connectionName, SqsJob $job): void
    {
        $this->events->dispatch(new JobProcessing(
            $connectionName,
            $job
        ));
    }

    /**
     * @see \Illuminate\Queue\Worker::raiseAfterJobEvent()
     */
    private function raiseAfterJobEvent(string $connectionName, SqsJob $job): void
    {
        $this->events->dispatch(new JobProcessed(
            $connectionName,
            $job
        ));
    }

    /**
     * @see \Illuminate\Queue\Worker::raiseExceptionOccurredJobEvent()
     */
    private function raiseExceptionOccurredJobEvent(string $connectionName, SqsJob $job, Throwable $e): void
    {
        $this->events->dispatch(new JobExceptionOccurred(
            $connectionName,
            $job,
            $e
        ));
    }
}
