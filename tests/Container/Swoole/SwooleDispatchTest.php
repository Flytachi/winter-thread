<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Swoole;

use Flytachi\Winter\Thread\Launch\AdaptiveLauncher;
use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Tests\Container\ChildProcessProbe;
use Flytachi\Winter\Thread\Tests\Fixtures\PidReportTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression for launching from inside a running coroutine.
 *
 * `proc_open` (via `posix_spawn`) corrupts the reactor's file descriptors, so a
 * *second* spawn inside one coroutine fails with "Bad file descriptor", and
 * `Swoole\Process` cannot be created at all while async-io threads are up.
 * {@see AdaptiveLauncher} therefore routes to {@see \Flytachi\Winter\Thread\Launch\SwooleLauncher}
 * inside a coroutine, which backgrounds through the shell (`Coroutine\System::exec`).
 * This proves several detached launches from one coroutine all succeed — and that
 * none of the ephemeral launchers lingers as a zombie.
 */
#[Group('container')]
#[Group('swoole')]
class SwooleDispatchTest extends TestCase
{
    use ChildProcessProbe;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole') || !class_exists('\Swoole\Runtime')) {
            $this->markTestSkipped('ext-swoole not available.');
        }
        if (!function_exists('posix_setsid')) {
            $this->markTestSkipped('ext-posix not available.');
        }
    }

    protected function tearDown(): void
    {
        if (class_exists('\Swoole\Runtime')) {
            \Swoole\Runtime::enableCoroutine(0);
        }
        Thread::bindLauncher(CliLauncher::adaptive());
    }

    public function testSeveralDetachedLaunchesInsideOneCoroutine(): void
    {
        Thread::bindLauncher(AdaptiveLauncher::adaptive());

        $files = [
            sys_get_temp_dir() . '/wt-swd-' . uniqid('', true) . '.pid',
            sys_get_temp_dir() . '/wt-swd-' . uniqid('', true) . '.pid',
            sys_get_temp_dir() . '/wt-swd-' . uniqid('', true) . '.pid',
        ];

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            \Swoole\Coroutine\run(function () use ($files): void {
                foreach ($files as $file) {
                    // The old proc_open path throws "Bad file descriptor" on the
                    // second of these; the Swoole backend must launch them all.
                    (new Thread(new PidReportTask($file)))->start(detached: true);
                }
            });
        } finally {
            \Swoole\Runtime::enableCoroutine(0);
        }

        $pids = [];
        foreach ($files as $file) {
            $deadline = time() + 5;
            while (!is_file($file) && time() < $deadline) {
                usleep(20_000);
            }
            $this->assertFileExists($file, 'each detached worker started and reported its PID');
            $pids[] = (int) file_get_contents($file);
        }

        foreach ($pids as $pid) {
            $this->assertGreaterThan(0, $pid, 'a real worker PID was reported');
        }
        $this->assertCount(count($files), array_unique($pids), 'the workers are distinct processes');
        $this->assertSame(0, $this->zombieChildCount(), 'no launcher lingers as a zombie');

        foreach ($pids as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}
