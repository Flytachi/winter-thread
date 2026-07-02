<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Engine;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Engine\ManualEngine;
use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Launch\Launcher;
use Flytachi\Winter\Thread\Launch\ProcessHandle;
use Flytachi\Winter\Thread\LaunchSpec;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Payload\TempFileTransport;
use Flytachi\Winter\Thread\Runner\ProcessRunner;
use Flytachi\Winter\Thread\ThreadException;
use PHPUnit\Framework\TestCase;

class EngineTest extends TestCase
{
    public function testAdaptiveDefaultsInCli(): void
    {
        $e = new AdaptiveEngine();
        $this->assertInstanceOf(PipeTransport::class, $e->transport());
        $this->assertInstanceOf(CliLauncher::class, $e->launcher());
        $this->assertInstanceOf(ProcessRunner::class, $e->runner());
        $this->assertStringEndsWith('wRunner', $e->runnerPath());
        $this->assertNull($e->security());
    }

    public function testAdaptiveHonorsOverrides(): void
    {
        $e = new AdaptiveEngine(secret: 's3cr3t', transport: new TempFileTransport(), binaryPath: '/usr/bin/php');
        $this->assertInstanceOf(TempFileTransport::class, $e->transport());
        $this->assertSame('/usr/bin/php', $e->binaryPath());
        $this->assertNotNull($e->security());
    }

    public function testManualIsCleanSlate(): void
    {
        $this->expectException(ThreadException::class);
        (new ManualEngine())->transport();
    }

    public function testManualWithersAreImmutable(): void
    {
        $base = new ManualEngine();
        $configured = $base->withTransport(new PipeTransport())->withBinaryPath('php')->withRunnerPath('wRunner');
        $this->assertInstanceOf(PipeTransport::class, $configured->transport());
        $this->assertSame('php', $configured->binaryPath());
        // Original stays clean:
        $this->expectException(ThreadException::class);
        $base->transport();
    }

    public function testAdaptiveHonorsInjectedLauncher(): void
    {
        $custom = $this->stubLauncher();
        $e = new AdaptiveEngine(launcher: $custom);
        $this->assertSame($custom, $e->launcher());
    }

    public function testManualWithLauncherIsUsedWithoutOtherConfig(): void
    {
        $custom = $this->stubLauncher();
        // No transport/binaryPath/runnerPath set — an injected launcher must be
        // returned as-is without trying to build a CliLauncher (which would throw).
        $engine = (new ManualEngine())->withLauncher($custom);
        $this->assertSame($custom, $engine->launcher());
    }

    private function stubLauncher(): Launcher
    {
        return new class implements Launcher {
            public function launch(LaunchSpec $spec): ProcessHandle
            {
                throw new \LogicException('unused');
            }
        };
    }
}
