<?php

declare(strict_types=1);

use Bref\LaravelBridge\Queue\SqsJob;
use Aws\Sqs\SqsClient;
use Illuminate\Container\Container;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class SqsJobTest extends TestCase
{
    protected $account;
    protected $queueName;
    protected $baseUrl;
    protected $releaseDelay;
    protected $queueUrl;
    protected $mockedSqsClient;
    protected $mockedContainer;
    protected $mockedJob;
    protected $mockedData;
    protected $mockedPayload;
    protected $mockedMessageId;
    protected $mockedReceiptHandle;
    protected $mockedJobData;

    protected function setUp(): void
    {
        $this->account = '1234567891011';
        $this->queueName = 'emails';
        $this->baseUrl = 'https://sqs.someregion.amazonaws.com';
        $this->releaseDelay = 0;

        // This is how the modified getQueue builds the queueUrl
        $this->queueUrl = $this->baseUrl.'/'.$this->account.'/'.$this->queueName;

        // Get a mock of the SqsClient
        $this->mockedSqsClient = m::mock(SqsClient::class);

        // Use Mockery to mock the IoC Container
        $this->mockedContainer = m::mock(Container::class);

        $this->mockedJob = 'foo';
        $this->mockedData = ['data'];
        $this->mockedPayload = json_encode(['job' => $this->mockedJob, 'data' => $this->mockedData, 'attempts' => 1]);
        $this->mockedMessageId = 'e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81';
        $this->mockedReceiptHandle = '0NNAq8PwvXuWv5gMtS9DJ8qEdyiUwbAjpp45w2m6M4SJ1Y+PxCh7R930NRB8ylSacEmoSnW18bgd4nK\/O6ctE+VFVul4eD23mA07vVoSnPI4F\/voI1eNCp6Iax0ktGmhlNVzBwaZHEr91BRtqTRM3QKd2ASF8u+IQaSwyl\/DGK+P1+dqUOodvOVtExJwdyDLy1glZVgm85Yw9Jf5yZEEErqRwzYz\/qSigdvW4sm2l7e4phRol\/+IjMtovOyH\/ukueYdlVbQ4OshQLENhUKe7RNN5i6bE\/e5x9bnPhfj2gbM';
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function testProperlyReleaseStandardSqs()
    {
        $job = $this->createJob();
        $job->getSqs()
            ->shouldReceive('deleteMessage')
            ->with(['QueueUrl' => $this->queueUrl, 'ReceiptHandle' => $this->mockedReceiptHandle])
            ->once();
        $job->getSqs()
            ->shouldReceive('sendMessage')
            ->with(
                $this->logicalAnd(
                    $this->arrayHasKey("MessageBody"),
                    $this->arrayHasKey("QueueUrl"),
                ),
            )
            ->once();
        $job->release($this->releaseDelay);
        $this->assertTrue($job->isReleased());
    }

    public function testProperlyReleaseFifoSqs()
    {
        $job = $this->createFifoJob();
        $job->getSqs()
            ->shouldReceive('deleteMessage')
            ->with(['QueueUrl' => $this->queueUrl.'.fifo', 'ReceiptHandle' => $this->mockedReceiptHandle])
            ->once();
        $job->getSqs()
            ->shouldReceive('sendMessage')
            ->with(
                $this->logicalAnd(
                    $this->arrayHasKey("MessageBody"),
                    $this->arrayHasKey("QueueUrl"),
                    $this->arrayHasKey("MessageGroupId"),
                    $this->arrayHasKey("MessageDeduplicationId"),
                ),
            )
            ->once();
        $job->release($this->releaseDelay);
        $this->assertTrue($job->isReleased());
    }

    protected function createJob()
    {
        $jobData = [
            'Body' => $this->mockedPayload,
            'MD5OfBody' => md5($this->mockedPayload),
            'ReceiptHandle' => $this->mockedReceiptHandle,
            'MessageId' => $this->mockedMessageId,
            'Attributes' => ['ApproximateReceiveCount' => 1],
        ];
        return new SqsJob(
            $this->mockedContainer,
            $this->mockedSqsClient,
            $jobData,
            'connection-name',
            $this->queueUrl
        );
    }

    protected function createFifoJob()
    {
        $jobData = [
            'Body' => $this->mockedPayload,
            'MD5OfBody' => md5($this->mockedPayload),
            'ReceiptHandle' => $this->mockedReceiptHandle,
            'MessageId' => $this->mockedMessageId,
            'Attributes' => [
                'ApproximateReceiveCount' => 1,
                'MessageGroupId' => 'group1',
                'MessageDeduplicationId' => 'deduplication1'
            ],
        ];
        return new SqsJob(
            $this->mockedContainer,
            $this->mockedSqsClient,
            $jobData,
            'connection-name',
            $this->queueUrl.'.fifo'
        );
    }
}
