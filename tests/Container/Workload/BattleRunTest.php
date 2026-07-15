<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Workload;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Tests\Container\ChildProcessProbe;
use Flytachi\Winter\Thread\Tests\Fixtures\BatchSumTask;
use Flytachi\Winter\Thread\Tests\Fixtures\FileIoTask;
use Flytachi\Winter\Thread\Tests\Fixtures\HashTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A realistic mixed battle run: a dozen heterogeneous jobs (CPU hashing,
 * arithmetic batches, file IO) launched in parallel, each verified against an
 * independently-computed expected result, with no zombies under load.
 */
#[Group('container')]
class BattleRunTest extends TestCase
{
    use ChildProcessProbe;

    protected function tearDown(): void
    {
        Thread::bindLauncher(CliLauncher::adaptive());
    }

    public function testDiverseWorkloadsInParallel(): void
    {
        $tmp = sys_get_temp_dir();
        /** @var array<int, array{thread: Thread, out: string, expected: string}> $jobs */
        $jobs = [];
        $cleanupDirs = [];

        // CPU-bound: iterated hashing.
        for ($i = 1; $i <= 4; $i++) {
            $seed = "seed-{$i}";
            $rounds = 500 * $i;
            $out = $tmp . '/wt-hash-' . uniqid('', true) . '.txt';
            $expected = $seed;
            for ($r = 0; $r < $rounds; $r++) {
                $expected = hash('sha256', $expected);
            }
            $jobs[] = [
                'thread'   => new Thread(new HashTask($seed, $rounds, $out)),
                'out'      => $out,
                'expected' => $expected,
            ];
        }

        // Arithmetic batches.
        for ($i = 1; $i <= 4; $i++) {
            $n = 1000 * $i;
            $out = $tmp . '/wt-sum-' . uniqid('', true) . '.txt';
            $jobs[] = [
                'thread'   => new Thread(new BatchSumTask($n, $out)),
                'out'      => $out,
                'expected' => (string) intdiv($n * ($n + 1), 2),
            ];
        }

        // IO-bound.
        for ($i = 1; $i <= 4; $i++) {
            $dir = $tmp . '/wt-io-' . uniqid('', true);
            mkdir($dir);
            $cleanupDirs[] = $dir;
            $count = 40;
            $out = $tmp . '/wt-io-out-' . uniqid('', true) . '.txt';
            $jobs[] = [
                'thread'   => new Thread(new FileIoTask($dir, $count, $out)),
                'out'      => $out,
                'expected' => (string) ($count * 100),
            ];
        }

        // Launch everything in parallel.
        foreach ($jobs as $job) {
            $job['thread']->start();
        }

        // Join and verify each result.
        foreach ($jobs as $job) {
            $this->assertSame(0, $job['thread']->join(), 'workload exit code');
            $this->assertSame(
                $job['expected'],
                (string) file_get_contents($job['out']),
                'workload produced an incorrect result',
            );
            @unlink($job['out']);
        }

        foreach ($cleanupDirs as $dir) {
            @rmdir($dir);
        }

        $this->assertSame(0, $this->zombieChildCount(), 'no zombies after the battle run');
    }
}
