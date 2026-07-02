<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Tests\Fixtures\EchoTask;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\TestCase;

class ThreadFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Thread::bindEngine(new AdaptiveEngine());
    }

    public function testDefaultEngineIsAdaptive(): void
    {
        $this->assertInstanceOf(AdaptiveEngine::class, Thread::engine());
    }

    public function testBindEngineIsUsed(): void
    {
        $spy = new AdaptiveEngine();
        Thread::bindEngine($spy);
        $this->assertSame($spy, Thread::engine());
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
