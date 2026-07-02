<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Metrics;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Tests\Fixtures\IdleTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Reports how much resident memory a spawned worker actually uses (a clean PHP
 * CLI process plus its payload), for an empty / 1 MB / 8 MB payload. Prints the
 * numbers to the console and asserts they are measurable.
 */
#[Group('container')]
class MemoryFootprintTest extends TestCase
{
    protected function tearDown(): void
    {
        Thread::bindEngine(new AdaptiveEngine());
    }

    private function rssMb(int $pid): float
    {
        // `ps -o rss=` reports resident set size in KiB (portable: Linux + macOS).
        $rssKb = (int) trim((string) shell_exec('ps -o rss= -p ' . $pid . ' 2>/dev/null'));
        return round($rssKb / 1024, 1);
    }

    public function testReportWorkerMemoryFootprint(): void
    {
        $cases = [
            'empty payload' => '',
            '1 MB payload'  => str_repeat('x', 1024 * 1024),
            '8 MB payload'  => str_repeat('x', 8 * 1024 * 1024),
        ];

        fwrite(STDOUT, "\n  --- winter-thread worker RSS ---\n");
        foreach ($cases as $label => $blob) {
            $thread = new Thread(new IdleTask($blob, 5));
            $pid = $thread->start();
            usleep(500_000); // let the process settle
            $mb = $this->rssMb($pid);
            fwrite(STDOUT, sprintf("  %-14s -> %5.1f MB  (pid %d)\n", $label, $mb, $pid));

            $thread->kill();
            $thread->join();

            $this->assertGreaterThan(0.0, $mb, 'worker RSS must be measurable');
        }
        fwrite(STDOUT, "  --------------------------------\n");
    }
}
