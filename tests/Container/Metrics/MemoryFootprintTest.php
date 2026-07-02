<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Metrics;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Tests\Fixtures\IdleTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Reports the resident memory (RSS) of a spawned worker. The default worker is
 * measured across empty / 1 MB / 8 MB payloads (showing how delivery scales the
 * footprint); a raw `php -n` process (no php.ini → no swoole/opcache/extensions)
 * is measured as the lean baseline, so heavy vs lean can be compared.
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

    private function leanBaselineMb(): float
    {
        // A minimal PHP process: no php.ini, so none of the shared extensions load.
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];
        $pipes = [];
        $proc = proc_open(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg('sleep(5);'), $desc, $pipes);
        $pid = proc_get_status($proc)['pid'];
        usleep(500_000);
        $mb = $this->rssMb($pid);
        proc_terminate($proc, SIGKILL);
        proc_close($proc);
        return $mb;
    }

    public function testReportWorkerMemoryFootprint(): void
    {
        fwrite(STDOUT, "\n  --- winter-thread worker RSS ---\n");

        foreach (['empty' => '', '1 MB' => str_repeat('x', 1024 * 1024), '8 MB' => str_repeat('x', 8 * 1024 * 1024)] as $label => $blob) {
            Thread::bindEngine(new AdaptiveEngine());
            $thread = new Thread(new IdleTask($blob, 5));
            $pid = $thread->start();
            usleep(500_000); // let the process settle
            $mb = $this->rssMb($pid);
            fwrite(STDOUT, sprintf("  default worker  %-5s -> %5.1f MB\n", $label, $mb));

            $thread->kill();
            $thread->join();

            $this->assertGreaterThan(0.0, $mb, 'worker RSS must be measurable');
        }

        $leanMb = $this->leanBaselineMb();
        fwrite(STDOUT, sprintf("  lean php -n     idle  -> %5.1f MB\n", $leanMb));
        fwrite(STDOUT, "  --------------------------------\n");

        $this->assertGreaterThan(0.0, $leanMb, 'lean baseline RSS must be measurable');
    }
}
