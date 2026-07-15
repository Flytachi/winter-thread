<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Metrics;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Launch\Launcher;
use Flytachi\Winter\Thread\Tests\Container\LeanWorker;
use Flytachi\Winter\Thread\Tests\Fixtures\MemoryReportTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Reports the resident memory (RSS) of a spawned worker for the default build
 * versus a lean worker (`php -n`, no swoole/opcache/extensions), across empty /
 * 1 MB / 8 MB payloads. The worker self-reports its RSS (the PID from proc_open
 * is the `sh` wrapper, not the PHP process), so the numbers are accurate on both
 * Linux and macOS. Printed to the console for comparison.
 */
#[Group('container')]
class MemoryFootprintTest extends TestCase
{
    use LeanWorker;

    protected function tearDown(): void
    {
        Thread::bindLauncher(CliLauncher::adaptive());
    }

    private function workerRssMb(Launcher $launcher, string $blob): float
    {
        Thread::bindLauncher($launcher);
        $out = sys_get_temp_dir() . '/wt-rss-' . uniqid('', true) . '.txt';
        $thread = new Thread(new MemoryReportTask($blob, $out));
        $thread->start();
        $thread->join();
        $rssKb = (int) trim((string) @file_get_contents($out));
        @unlink($out);
        return round($rssKb / 1024, 1);
    }

    public function testReportWorkerMemoryFootprint(): void
    {
        $payloads = [
            'empty' => '',
            '1 MB'  => str_repeat('x', 1024 * 1024),
            '8 MB'  => str_repeat('x', 8 * 1024 * 1024),
        ];
        $launchers = [
            'default build' => fn(): Launcher => CliLauncher::adaptive(),
            'lean (php -n)' => fn(): Launcher => $this->leanLauncher(),
        ];

        fwrite(STDOUT, "\n  --- winter-thread worker RSS ---\n");
        foreach ($launchers as $engineLabel => $factory) {
            foreach ($payloads as $payloadLabel => $blob) {
                $mb = $this->workerRssMb($factory(), $blob);
                fwrite(STDOUT, sprintf("  %-14s %-5s -> %5.1f MB\n", $engineLabel, $payloadLabel, $mb));
                $this->assertGreaterThan(0.0, $mb, 'worker RSS must be measurable');
            }
        }
        fwrite(STDOUT, "  --------------------------------\n");
    }
}
