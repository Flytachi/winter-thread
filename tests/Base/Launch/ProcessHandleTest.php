<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Base\Launch;

use Flytachi\Winter\Thread\Launch\ProcessHandle;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Payload\StagedPayload;
use PHPUnit\Framework\TestCase;

class ProcessHandleTest extends TestCase
{
    private function handleFor(string $cmd): ProcessHandle
    {
        $desc = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];
        $pipes = [];
        $proc = proc_open($cmd, $desc, $pipes);
        $status = proc_get_status($proc);
        return new ProcessHandle($proc, $pipes, $status['pid'], new PipeTransport(), new StagedPayload(['pipe', 'r']));
    }

    public function testJoinReturnsExitCode(): void
    {
        $h = $this->handleFor('exit 3');
        $this->assertSame(3, $h->join());
        $this->assertSame(3, $h->getExitCode());
    }

    public function testReapIsFalseWhileRunningThenTrue(): void
    {
        $h = $this->handleFor('sleep 30');
        $this->assertFalse($h->reap());
        $this->assertTrue($h->isAlive());
        $h->signal(SIGKILL);
        $h->join();
        $this->assertFalse($h->isAlive());
    }

    public function testReapCollectsZombie(): void
    {
        $h = $this->handleFor('true');
        $pid = $h->getPid();
        while ($h->isAlive()) {
            usleep(20_000);
        }
        $h->reap();
        $state = trim((string) shell_exec('ps -o state= -p ' . $pid . ' 2>/dev/null'));
        $this->assertStringStartsNotWith('Z', $state);
    }

    public function testReapDoesNotBlockOnLiveProcess(): void
    {
        $h = $this->handleFor('sleep 5');

        $t0 = microtime(true);
        $reaped = $h->reap();
        $elapsed = microtime(true) - $t0;

        $this->assertFalse($reaped, 'reap() must not collect a still-running process');
        $this->assertLessThan(0.5, $elapsed, 'reap() must be non-blocking on a live process');

        $h->signal(SIGKILL);
        $h->join();
    }

    public function testDetachIsNonBlockingAndLeavesProcessRunning(): void
    {
        $h = $this->handleFor('sleep 5');
        $pid = $h->getPid();

        $t0 = microtime(true);
        $h->detach();
        $elapsed = microtime(true) - $t0;

        // A proc_close() regression here would block until the child exits (~5s).
        $this->assertLessThan(0.5, $elapsed, 'detach() must be non-blocking on a live process');
        $this->assertTrue(posix_kill($pid, 0), 'detach() must leave the worker running, not kill it');

        // Clean up the abandoned child ourselves.
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }

    public function testDestructorDoesNotBlockOnLiveProcess(): void
    {
        $h = $this->handleFor('sleep 5');
        $pid = $h->getPid();

        $t0 = microtime(true);
        unset($h);
        gc_collect_cycles();
        $elapsed = microtime(true) - $t0;

        // Dropping a Thread whose child is still running must never hang the parent.
        $this->assertLessThan(0.5, $elapsed, 'destructor must not block on a live child');

        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
}
