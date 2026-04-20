<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\Console\Commands;

use Bref\LaravelBridge\Tests\TestCase;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Mockery as m;

class QueueFailedJobsCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function testCountUsesCountableInterfaceWhenAvailable(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class, CountableFailedJobProvider::class);
        $failer->shouldReceive('count')->once()->withNoArgs()->andReturn(42);
        $failer->shouldNotReceive('all');
        $this->bindFailer($failer);

        $exitCode = Artisan::call('bref:failed-jobs:count');

        $this->assertSame(0, $exitCode);
        $this->assertSame(['total' => 42], json_decode(trim(Artisan::output()), true));
    }

    public function testCountFallsBackToAllForNonCountableProviders(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('all')->once()->andReturn([(object) [], (object) [], (object) []]);
        $this->bindFailer($failer);

        $exitCode = Artisan::call('bref:failed-jobs:count');

        $this->assertSame(0, $exitCode);
        $this->assertSame(['total' => 3], json_decode(trim(Artisan::output()), true));
    }

    public function testListReturnsFormattedJobs(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('all')->once()->andReturn([
            $this->makeFailedJob(1, 'App\\Jobs\\SendEmail'),
            $this->makeFailedJob(2, 'App\\Jobs\\Report'),
        ]);
        $this->bindFailer($failer);

        $exitCode = Artisan::call('bref:failed-jobs:list');
        $data = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $data['total']);
        $this->assertSame(2, $data['returned']);
        $this->assertCount(2, $data['jobs']);

        $first = $data['jobs'][0];
        $this->assertSame(1, $first['id']);
        $this->assertSame('uuid-1', $first['uuid']);
        $this->assertSame('sqs', $first['connection']);
        $this->assertSame('default', $first['queue']);
        $this->assertSame('App\\Jobs\\SendEmail', $first['name']);
        $this->assertSame('2026-04-20 12:00:00', $first['failed_at']);
        $this->assertSame('RuntimeException: fail #1', $first['exception']);
        $this->assertIsArray($first['payload']);
    }

    public function testListAppliesLimit(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('all')->once()->andReturn([
            $this->makeFailedJob(1, 'A'),
            $this->makeFailedJob(2, 'B'),
            $this->makeFailedJob(3, 'C'),
        ]);
        $this->bindFailer($failer);

        $exitCode = Artisan::call('bref:failed-jobs:list', ['--limit' => 2]);
        $data = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, $data['total']);
        $this->assertSame(2, $data['returned']);
        $this->assertSame([1, 2], array_column($data['jobs'], 'id'));
    }

    public function testListReturnsEmptyArrayWhenNoFailedJobs(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('all')->once()->andReturn([]);
        $this->bindFailer($failer);

        $exitCode = Artisan::call('bref:failed-jobs:list');
        $data = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $data['total']);
        $this->assertSame(0, $data['returned']);
        $this->assertSame([], $data['jobs']);
    }

    public function testShowReturnsFoundJob(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('find')->with('42')->once()->andReturn(
            $this->makeFailedJob(42, 'App\\Jobs\\SendEmail')
        );
        $this->bindFailer($failer);

        $exitCode = Artisan::call('bref:failed-jobs:show', ['id' => '42']);
        $data = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(42, $data['id']);
        $this->assertSame('App\\Jobs\\SendEmail', $data['name']);
    }

    public function testShowReturnsNullAndFailureExitCodeWhenNotFound(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('find')->with('99')->once()->andReturn(null);
        $this->bindFailer($failer);

        $exitCode = Artisan::call('bref:failed-jobs:show', ['id' => '99']);

        $this->assertSame(1, $exitCode);
        $this->assertNull(json_decode(trim(Artisan::output()), true));
    }

    private function bindFailer(FailedJobProviderInterface $failer): void
    {
        $this->app->instance(FailedJobProviderInterface::class, $failer);
        $this->app->instance('queue.failer', $failer);
    }

    private function makeFailedJob(int $id, string $displayName): object
    {
        return (object) [
            'id' => $id,
            'connection' => 'sqs',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => "uuid-$id",
                'displayName' => $displayName,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            ]),
            'exception' => "RuntimeException: fail #$id",
            'failed_at' => '2026-04-20 12:00:00',
        ];
    }
}
