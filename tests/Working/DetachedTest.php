<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Working;

use Flytachi\Winter\Thread\Tests\Fixtures\PidReportTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\TestCase;

/**
 * Basic detached-mode operability: the ephemeral launcher exits immediately
 * (reaped by join), and the real worker is reparented to init (ppid == 1),
 * so a long-lived parent never accumulates a zombie.
 */
class DetachedTest extends TestCase
{
    public function testDetachedWorkerReparentsToInit(): void
    {
        if (!function_exists('posix_setsid')) {
            $this->markTestSkipped('ext-posix not available.');
        }

        $pidFile = sys_get_temp_dir() . '/wt-detach-' . uniqid() . '.pid';

        $thread = new Thread(new PidReportTask($pidFile));
        $launcherPid = $thread->start(detached: true);
        $thread->join(); // reap the ephemeral launcher process

        $deadline = time() + 5;
        while (!is_file($pidFile) && time() < $deadline) {
            usleep(20_000);
        }

        $this->assertFileExists($pidFile);
        $workerPid = (int) file_get_contents($pidFile);
        $this->assertGreaterThan(0, $workerPid);
        $this->assertNotSame($launcherPid, $workerPid);

        $ppid = trim((string) shell_exec('ps -o ppid= -p ' . $workerPid . ' 2>/dev/null'));
        $this->assertSame('1', $ppid, 'detached worker should be reparented to init (pid 1)');

        @unlink($pidFile);
    }
}
