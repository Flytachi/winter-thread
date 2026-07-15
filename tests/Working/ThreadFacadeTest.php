<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Working;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Tests\Fixtures\EchoTask;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\TestCase;

class ThreadFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Thread::bindLauncher(CliLauncher::adaptive());
    }

    public function testDefaultEngineIsAdaptive(): void
    {
        $this->assertInstanceOf(CliLauncher::class, Thread::launcher());
    }

    public function testBindEngineIsUsed(): void
    {
        $spy = CliLauncher::adaptive();
        Thread::bindLauncher($spy);
        $this->assertSame($spy, Thread::launcher());
    }

    public function testStartJoinRoundTripThroughFacade(): void
    {
        $thread = new Thread(new SleepTask(0));
        $pid = $thread->start();
        $this->assertGreaterThan(0, $pid);
        $this->assertSame(0, $thread->join());
        $this->assertSame(0, $thread->getExitCode());
    }

    public function testFileOutputThroughFacade(): void
    {
        $log = sys_get_temp_dir() . '/wt-facade-' . uniqid() . '.log';
        (new Thread(new EchoTask('facade-hello')))->start(outputTarget: $log);
        usleep(300_000);
        $this->assertStringContainsString('facade-hello', (string) file_get_contents($log));
        unlink($log);
    }
}
