<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Queue;

use Aws\CommandInterface;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;

class QueueHandlerTest extends QueueTestCase
{
    /** @var MessageLogged[] */
    private array $logs = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

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

    public function testAJobThatTimesOutFailsWithTheTimeoutException(): void
    {
        $failureFile = tempnam(sys_get_temp_dir(), 'bref-timeout-');

        // The timeout exits the process, so the job runs in a child process
        $pid = pcntl_fork();
        if ($pid === 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $this->runJob(new TimingOutJob($failureFile));
            exit(0);
        }
        pcntl_waitpid($pid, $status);

        $this->assertSame(1, pcntl_wexitstatus($status));
        $expected = class_exists(TimeoutExceededException::class)
            ? TimeoutExceededException::class
            : MaxAttemptsExceededException::class;
        $this->assertSame($expected, file_get_contents($failureFile));
        unlink($failureFile);
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
