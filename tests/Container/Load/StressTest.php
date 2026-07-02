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

    public function testManyConcurrentThreadsCompleteCorrectly(): void
    {
        $count = 40;

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

        foreach ($outs as $out) {
            $this->assertSame('500500', (string) file_get_contents($out), 'each worker must compute the correct result');
            @unlink($out);
        }

        $this->assertSame(0, $this->zombieChildCount(), 'no zombies after concurrency stress');
    }
}
