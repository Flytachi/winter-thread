<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Working;

use Flytachi\Winter\Thread\Tests\Fixtures\PidReportTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\TestCase;

/**
 * Basic detached-mode operability: the ephemeral launcher exits immediately
 * (reaped by join), and the real worker is reparented away from our process tree
 * (to init, or to the nearest child-subreaper), so a long-lived parent never
 * accumulates a zombie.
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

        // The detached worker must be reparented OFF our process tree so the parent
        // never accumulates a zombie. The new parent is PID 1 (init) on a plain
        // system, or the nearest PR_SET_CHILD_SUBREAPER ancestor (e.g. `systemd
        // --user`, which owns most desktop login sessions) — so we assert it left,
        // not that it landed on a specific PID.
        $ppid = trim((string) shell_exec('ps -o ppid= -p ' . $workerPid . ' 2>/dev/null'));
        $this->assertNotSame('', $ppid, 'worker process should still exist to be inspected');
        $this->assertNotSame(
            (string) $launcherPid,
            $ppid,
            'detached worker must be reparented away from its ephemeral launcher',
        );
        $this->assertNotSame(
            (string) getmypid(),
            $ppid,
            'detached worker must not remain a child of the dispatching (parent) process',
        );

        @unlink($pidFile);
    }
}
