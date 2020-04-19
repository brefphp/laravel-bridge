<?php declare(strict_types=1);

namespace Bref\LaravelBridge;

use Bref\Event\Sqs\SqsRecord;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\Job;
use RuntimeException;

/**
 * This is similar to `Illuminate\Queue\Jobs\SqsJob`, except that the official class
 * is based on the JSON structure from the SQS API result.
 *
 * This class is based on the JSON structure from the AWS Lambda event when triggered by SQS.
 *
 * @see \Illuminate\Queue\Jobs\SqsJob
 *
 * @deprecated
 */
final class SqsJob extends Job implements \Illuminate\Contracts\Queue\Job
{
    /** @var SqsRecord */
    private $sqsRecord;

    public function __construct(Container $container, SqsRecord $sqsRecord, string $connectionName, string $queue)
    {
        $this->sqsRecord = $sqsRecord;
        $this->queue = $queue;
        $this->container = $container;
        $this->connectionName = $connectionName;
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return $this->sqsRecord->getApproximateReceiveCount();
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return $this->sqsRecord->getMessageId();
    }

    /**
     * Get the raw body string for the job.
     */
    public function getRawBody(): string
    {
        return $this->sqsRecord->getBody();
    }

    public function release($delay = 0): void
    {
        throw new RuntimeException('When using the Bref bridge with SQS, you must let AWS Lambda release jobs automatically');
    }

    public function delete(): void
    {
        throw new RuntimeException('When using the Bref bridge with SQS, you must let AWS Lambda delete jobs automatically');
    }
}
