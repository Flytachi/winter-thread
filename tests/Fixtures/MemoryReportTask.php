<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

/**
 * Holds a payload blob and writes the worker's OWN resident memory (RSS, in KiB)
 * to a file. Self-reporting is the only reliable way to measure the real worker:
 * the PID returned by proc_open is the `sh -c` wrapper, not the PHP process.
 */
class MemoryReportTask implements Runnable
{
    public function __construct(
        private string $blob,
        private string $outFile,
    ) {
    }

    public function run(array $args): void
    {
        $held = $this->blob;
        file_put_contents($this->outFile, (string) self::selfRssKb());
        unset($held);
    }

    public static function selfRssKb(): int
    {
        $status = @file_get_contents('/proc/self/status');
        if ($status !== false && preg_match('/^VmRSS:\s+(\d+)/m', $status, $m) === 1) {
            return (int) $m[1];
        }
        // macOS / no procfs: ask ps about our own PID.
        return (int) trim((string) shell_exec('ps -o rss= -p ' . getmypid() . ' 2>/dev/null'));
    }
}
