<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests;

use Flytachi\Winter\Thread\Signal;
use Flytachi\Winter\Thread\Thread;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use PHPUnit\Framework\TestCase;

class SignalTest extends TestCase
{
    // --- isProcessRunning ---

    public function testIsProcessRunningForCurrentProcess(): void
    {
        $this->assertTrue(Signal::isProcessRunning(getmypid()));
    }

    public function testIsProcessRunningReturnsFalseForNonExistentPid(): void
    {
        $this->assertFalse(Signal::isProcessRunning(2_000_000_000));
    }

    // --- interrupt ---

    public function testInterruptSendsSignal(): void
    {
        $thread = new Thread(new SleepTask(30));
        $pid = $thread->start();
        $this->assertTrue(Signal::interrupt($pid));
        usleep(300_000);
        $this->assertFalse($thread->isAlive());
    }

    public function testInterruptReturnsFalseForNonExistentPid(): void
    {
        $this->assertFalse(Signal::interrupt(2_000_000_000));
    }

    // --- termination ---

    public function testTerminationSendsSignal(): void
    {
        $thread = new Thread(new SleepTask(30));
        $pid = $thread->start();
        $this->assertTrue(Signal::termination($pid));
        usleep(300_000);
        $this->assertFalse($thread->isAlive());
    }

    public function testTerminationReturnsFalseForNonExistentPid(): void
    {
        $this->assertFalse(Signal::termination(2_000_000_000));
    }

    // --- kill ---

    public function testKillSendsSignal(): void
    {
        $thread = new Thread(new SleepTask(30));
        $pid = $thread->start();
        $this->assertTrue(Signal::kill($pid));
        usleep(300_000);
        $this->assertFalse($thread->isAlive());
    }

    public function testKillReturnsFalseForNonExistentPid(): void
    {
        $this->assertFalse(Signal::kill(2_000_000_000));
    }

    // --- close (SIGHUP) ---

    public function testCloseSendsHupSignal(): void
    {
        $thread = new Thread(new SleepTask(30));
        $pid = $thread->start();
        $this->assertTrue(Signal::close($pid));
        usleep(300_000);
        $this->assertFalse($thread->isAlive());
    }

    public function testCloseReturnsFalseForNonExistentPid(): void
    {
        $this->assertFalse(Signal::close(2_000_000_000));
    }

    // --- wait ---

    public function testWaitReturnsTrueWhenProcessTerminates(): void
    {
        $thread = new Thread(new SleepTask(0));
        $pid = $thread->start();
        $this->assertTrue(Signal::wait($pid, timeout: 5));
    }

    public function testWaitReturnsFalseOnTimeout(): void
    {
        $thread = new Thread(new SleepTask(30));
        $pid = $thread->start();
        $result = Signal::wait($pid, timeout: 1);
        $this->assertFalse($result);
        Signal::kill($pid);
    }

    // --- interruptAndWait ---

    public function testInterruptAndWaitTerminatesAndWaits(): void
    {
        $thread = new Thread(new SleepTask(30));
        $pid = $thread->start();
        $this->assertTrue(Signal::interruptAndWait($pid, timeout: 5));
        $this->assertFalse($thread->isAlive());
    }

    // --- terminationAndWait ---

    public function testTerminationAndWaitTerminatesAndWaits(): void
    {
        $thread = new Thread(new SleepTask(30));
        $pid = $thread->start();
        $this->assertTrue(Signal::terminationAndWait($pid, timeout: 5));
        $this->assertFalse($thread->isAlive());
    }

    // --- closeAndWait ---

    public function testCloseAndWaitSendsHupAndWaits(): void
    {
        $thread = new Thread(new SleepTask(30));
        $pid = $thread->start();
        $this->assertTrue(Signal::closeAndWait($pid, timeout: 5));
        $this->assertFalse($thread->isAlive());
    }
}
