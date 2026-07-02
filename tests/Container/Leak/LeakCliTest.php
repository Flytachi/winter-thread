<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Leak;

use Flytachi\Winter\Thread\Tests\Container\ChildProcessProbe;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Resource-leak guards for the plain CLI path: no zombies, no file-descriptor
 * growth, no monotonic memory growth across many spawn/reap cycles.
 */
#[Group('container')]
#[Group('leak')]
class LeakCliTest extends TestCase
{
    use ChildProcessProbe;

    public function testNoZombiesAfterAttachedReapCycles(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $thread = new Thread(new SleepTask(0));
            $thread->start();
            $thread->join();
        }
        usleep(200_000);
        $this->assertSame(0, $this->zombieChildCount());
    }

    public function testFileDescriptorsDoNotLeak(): void
    {
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('requires /proc (Linux container)');
        }

        // Warm up, then measure a stable baseline.
        for ($i = 0; $i < 5; $i++) {
            (new Thread(new SleepTask(0)))->start();
        }
        gc_collect_cycles();
        $baseline = count((array) glob('/proc/self/fd/*'));

        for ($i = 0; $i < 30; $i++) {
            $thread = new Thread(new SleepTask(0));
            $thread->start();
            $thread->join();
            unset($thread);
        }
        gc_collect_cycles();
        $after = count((array) glob('/proc/self/fd/*'));

        $this->assertLessThanOrEqual(
            $baseline + 3,
            $after,
            "file descriptors leaked: baseline={$baseline} after={$after}",
        );
    }

    public function testMemoryDoesNotGrow(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $thread = new Thread(new SleepTask(0));
            $thread->start();
            $thread->join();
        }
        gc_collect_cycles();
        $baseline = memory_get_usage(true);

        for ($i = 0; $i < 40; $i++) {
            $thread = new Thread(new SleepTask(0));
            $thread->start();
            $thread->join();
            unset($thread);
        }
        gc_collect_cycles();
        $after = memory_get_usage(true);

        // Allow one allocator page-bucket of slack; the point is to catch
        // unbounded growth, not to assert byte-exact stability.
        $this->assertLessThanOrEqual(
            $baseline + 1_048_576,
            $after,
            "memory grew: baseline={$baseline} after={$after}",
        );
    }
}
