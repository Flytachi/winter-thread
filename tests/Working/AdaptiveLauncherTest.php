<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Working;

use Flytachi\Winter\Thread\Launch\AdaptiveLauncher;
use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Tests\Fixtures\PayloadProbeTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\TestCase;

/**
 * {@see AdaptiveLauncher} routing outside a Swoole runtime.
 *
 * With no coroutine and no hooks active — the ordinary CLI / FPM case — the
 * adaptive launcher must delegate to {@see CliLauncher} and behave exactly like it:
 * a bound `AdaptiveLauncher` is a drop-in for the default. The in-coroutine path
 * (routing to the Swoole backend) is exercised by the swoole-group container test.
 */
class AdaptiveLauncherTest extends TestCase
{
    protected function tearDown(): void
    {
        Thread::bindLauncher(CliLauncher::adaptive());
    }

    public function testDelegatesToCliOutsideACoroutine(): void
    {
        Thread::bindLauncher(AdaptiveLauncher::adaptive());

        $file = sys_get_temp_dir() . '/wt-adaptive-' . uniqid('', true) . '.txt';

        $thread = new Thread(new PayloadProbeTask($file));
        $thread->start(['tag' => 'cli']);
        $exit = $thread->join();

        $this->assertSame(0, $exit, 'the delegated CLI launch ran to completion');
        $this->assertFileExists($file);
        $this->assertSame('ran:cli', file_get_contents($file), 'the payload round-tripped through the CLI backend');

        @unlink($file);
    }
}
