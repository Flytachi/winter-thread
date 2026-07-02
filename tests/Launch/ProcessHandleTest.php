<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Launch;

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
}
