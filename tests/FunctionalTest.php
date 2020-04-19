<?php declare(strict_types=1);

namespace Bref\Test\LaravelBridge;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FunctionalTest extends TestCase
{
    public function test()
    {
        // We run the test via a separate process to ensure that we have a
        // blank Laravel kernel not impacted by
        $handler = new Process(['php', 'test-trigger.php']);
        $handler->setWorkingDirectory(__DIR__);
        $handler->mustRun();
        $this->assertEquals('Processing podcast 12345', $handler->getOutput());
    }
}
