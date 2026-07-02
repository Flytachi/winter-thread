<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Load;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Tests\Container\ChildProcessProbe;
use Flytachi\Winter\Thread\Tests\Fixtures\BatchSumTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Concurrency stress: dozens of processes launched at once must all finish with
 * the correct result and leave no zombies — proving the engine survives fd /
 * process pressure under real parallel load.
 */
#[Group('container')]
class StressTest extends TestCase
{
    use ChildProcessProbe;

    protected function tearDown(): void
    {
        Thread::bindEngine(new AdaptiveEngine());
    }

    private function fdCount(): string
    {
        return is_dir('/proc/self/fd') ? (string) count((array) glob('/proc/self/fd/*')) : 'n/a';
    }

    public function testManyConcurrentThreadsCompleteCorrectly(): void
    {
        $count = 40;
        $fdBefore = $this->fdCount();
        $started = microtime(true);

        /** @var array<int, Thread> $threads */
        $threads = [];
        $outs = [];
        for ($i = 0; $i < $count; $i++) {
            $out = sys_get_temp_dir() . '/wt-stress-' . uniqid('', true) . '.txt';
            $outs[$i] = $out;
            $thread = new Thread(new BatchSumTask(1000, $out));
            $thread->start();
            $threads[$i] = $thread;
        }

        foreach ($threads as $thread) {
            $this->assertSame(0, $thread->join(), 'every concurrent worker must exit cleanly');
        }

        $elapsed = microtime(true) - $started;

        foreach ($outs as $out) {
            $this->assertSame('500500', (string) file_get_contents($out), 'each worker must compute the correct result');
            @unlink($out);
        }

        $zombies = $this->zombieChildCount();
        $fdAfter = $this->fdCount();

        fwrite(STDOUT, sprintf(
            "\n  stress: %d concurrent workers in %.2fs; zombies=%d; open fds %s -> %s\n",
            $count,
            $elapsed,
            $zombies,
            $fdBefore,
            $fdAfter,
        ));

        $this->assertSame(0, $zombies, 'no zombies after concurrency stress');
    }
}
