<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Working;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Engine\ManualEngine;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Tests\Fixtures\FailTask;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use Flytachi\Winter\Thread\Thread;
use Flytachi\Winter\Thread\ThreadException;
use PHPUnit\Framework\TestCase;

/**
 * Fault-tolerance and fool-proofing: failures must surface loudly (an exception
 * or a non-zero exit) — never a silent "dispatch succeeded" that breaks later.
 */
class FailureModesTest extends TestCase
{
    protected function tearDown(): void
    {
        Thread::bindEngine(new AdaptiveEngine());
    }

    private function runnerPath(): string
    {
        return dirname(__DIR__, 2) . '/wRunner';
    }

    public function testStartWhileAliveThrows(): void
    {
        Thread::bindEngine(new AdaptiveEngine());
        $thread = new Thread(new SleepTask(3));
        $thread->start();

        try {
            $thread->start();
            $this->fail('expected ThreadException when starting an already-running thread');
        } catch (ThreadException $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        } finally {
            $thread->kill();
            $thread->join();
        }
    }

    public function testMissingBinaryIsNotSilentSuccess(): void
    {
        Thread::bindEngine(
            (new ManualEngine())
                ->withTransport(new PipeTransport())
                ->withBinaryPath('/nonexistent/php-xyz')
                ->withRunnerPath($this->runnerPath()),
        );
        $this->assertNotSilentSuccess(new Thread(new SleepTask(0)));
    }

    public function testMissingRunnerIsNotSilentSuccess(): void
    {
        Thread::bindEngine(
            (new ManualEngine())
                ->withTransport(new PipeTransport())
                ->withBinaryPath(PHP_BINARY)
                ->withRunnerPath('/nonexistent/wRunner-xyz'),
        );
        $this->assertNotSilentSuccess(new Thread(new SleepTask(0)));
    }

    public function testRunnableExceptionYieldsNonZeroExit(): void
    {
        Thread::bindEngine(new AdaptiveEngine());
        $thread = new Thread(new FailTask());
        $thread->start();
        $this->assertNotSame(0, $thread->join(), 'a throwing Runnable must exit non-zero');
    }

    public function testOperationsAfterDetachAreInert(): void
    {
        Thread::bindEngine(new AdaptiveEngine());
        $thread = new Thread(new SleepTask(0));
        $pid = $thread->start();
        $thread->detach();

        $this->assertFalse($thread->isAlive());
        $this->assertTrue($thread->reap());
        $this->assertFalse($thread->kill());
        $this->assertFalse($thread->pause());
        $this->assertSame(-1, $thread->join());

        // Reap the abandoned child ourselves so the test leaves nothing behind.
        if ($pid !== null && function_exists('pcntl_waitpid')) {
            @pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Asserts that a doomed launch never reports success: it either throws a
     * ThreadException at start, or the child exits with a non-zero code.
     */
    private function assertNotSilentSuccess(Thread $thread): void
    {
        try {
            $thread->start();
        } catch (ThreadException) {
            $this->addToAssertionCount(1);
            return;
        }
        $exit = $thread->join();
        $this->assertNotSame(0, $exit, 'a broken launch must not report exit code 0');
    }
}
