<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Working;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Launch\ProcessHandle;
use Flytachi\Winter\Thread\LaunchSpec;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use PHPUnit\Framework\TestCase;

/**
 * Demonstrates the framework-facing Pool primitive: drive the low-level
 * Launcher + ProcessHandle directly (no Thread facade), harvesting finished
 * children in one non-blocking reap() loop, leaving no zombies behind.
 */
class PoolLoopTest extends TestCase
{
    public function testReapLoopHarvestsAllWithoutZombies(): void
    {
        $launcher = CliLauncher::adaptive();

        /** @var array<int, ProcessHandle> $handles */
        $handles = [];
        $pids = [];
        for ($i = 0; $i < 6; $i++) {
            $spec = new LaunchSpec(
                payload: \Opis\Closure\serialize(new SleepTask(0)),
                name: 'pool-worker',
            );
            $handle = $launcher->launch($spec);
            $handles[$i] = $handle;
            $pids[$i] = $handle->getPid();
        }

        $deadline = time() + 10;
        while ($handles !== [] && time() < $deadline) {
            $handles = array_filter($handles, static fn(ProcessHandle $h): bool => !$h->reap());
            usleep(20_000);
        }

        $this->assertSame([], $handles, 'every handle was reaped by the loop');

        foreach ($pids as $pid) {
            $state = trim((string) shell_exec('ps -o state= -p ' . $pid . ' 2>/dev/null'));
            $this->assertStringStartsNotWith('Z', $state, "pid {$pid} must not be a zombie");
        }
    }
}
