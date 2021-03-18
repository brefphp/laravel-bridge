<?php declare(strict_types=1);

namespace Bref\Test\LaravelBridge;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FunctionalTest extends TestCase
{
    public function test run without errors()
    {
        // We run the test via a separate process to ensure that we have a
        // blank Laravel kernel not impacted by
        $handler = new Process(['php', 'test-trigger.php']);
        $handler->setWorkingDirectory(__DIR__);
        $handler->mustRun();
        $this->assertEquals("Before job\nProcessing podcast 12345\nAfter job\n", $handler->getOutput());
        $this->assertEmpty($handler->getErrorOutput());
    }

    public function test make an error without view compiled path()
    {
        $handler = new Process(["php", "test-trigger.php"]);
        $handler->setEnv(["VIEW_COMPILED_PATH" => null]);
        $handler->setWorkingDirectory(__DIR__);
        $handler->mustRun();
        $this->assertNotEmpty($handler->getErrorOutput());
    }
}
