<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Load;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Tests\Container\ChildProcessProbe;
use Flytachi\Winter\Thread\Tests\Fixtures\BatchSumTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Concurrency stress at increasing scale. 40 and 100 are launched all-at-once
 * (peak concurrency); 300 runs through a bounded reap-pool (≤ maxConcurrent in
 * flight) — the realistic high-volume pattern that avoids exhausting RAM/fds.
 * Every worker must return the correct result with no zombies; throughput is
 * printed to the console.
 */
#[Group('container')]
class StressTest extends TestCase
{
    use ChildProcessProbe;

    protected function tearDown(): void
    {
        Thread::bindEngine(new AdaptiveEngine());
    }

    /** @return array<string, array{int, int}> */
    public static function loadProvider(): array
    {
        return [
            '40 all-at-once'   => [40, 40],
            '100 all-at-once'  => [100, 100],
            '300 pooled (≤50)' => [300, 50],
        ];
    }

    private function fdCount(): string
    {
        return is_dir('/proc/self/fd') ? (string) count((array) glob('/proc/self/fd/*')) : 'n/a';
    }

    #[DataProvider('loadProvider')]
    public function testConcurrencyScaling(int $total, int $maxConcurrent): void
    {
        Thread::bindEngine(new AdaptiveEngine());
        $fdBefore = $this->fdCount();
        $startedAt = microtime(true);

        /** @var array<int, array{thread: Thread, out: string}> $inflight */
        $inflight = [];
        $launched = 0;
        $completed = 0;

        while ($launched < $total || $inflight !== []) {
            while ($launched < $total && count($inflight) < $maxConcurrent) {
                $out = sys_get_temp_dir() . '/wt-stress-' . uniqid('', true) . '.txt';
                $thread = new Thread(new BatchSumTask(1000, $out));
                $thread->start();
                $inflight[$launched] = ['thread' => $thread, 'out' => $out];
                $launched++;
            }

            foreach ($inflight as $key => $job) {
                if ($job['thread']->reap()) {
                    $this->assertSame(0, $job['thread']->getExitCode(), 'worker must exit cleanly');
                    $this->assertSame('500500', (string) file_get_contents($job['out']), 'worker result must be correct');
                    @unlink($job['out']);
                    $completed++;
                    unset($inflight[$key]);
                }
            }

            usleep(5_000);
        }

        $elapsed = microtime(true) - $startedAt;
        $zombies = $this->zombieChildCount();

        fwrite(STDOUT, sprintf(
            "\n  stress: total=%d, max_concurrent=%d -> %.2fs (%.0f proc/s); zombies=%d; fds %s -> %s\n",
            $total,
            $maxConcurrent,
            $elapsed,
            $elapsed > 0 ? $total / $elapsed : 0,
            $zombies,
            $fdBefore,
            $this->fdCount(),
        ));

        $this->assertSame($total, $completed, 'every worker must complete');
        $this->assertSame(0, $zombies, 'no zombies after the stress run');
    }
}
