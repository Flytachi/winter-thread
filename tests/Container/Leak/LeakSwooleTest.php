<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Leak;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Tests\Container\ChildProcessProbe;
use Flytachi\Winter\Thread\Tests\Fixtures\PayloadProbeTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Under SWOOLE_HOOK_ALL, pipe file descriptors get intercepted and corrupted.
 * The AdaptiveEngine must switch to a file/shm transport inside an active Swoole
 * runtime so the payload is delivered intact and no worker lingers as a zombie.
 */
#[Group('container')]
#[Group('swoole')]
#[Group('leak')]
class LeakSwooleTest extends TestCase
{
    use ChildProcessProbe;

    public function testPayloadSurvivesSwooleHooks(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole not available.');
        }

        $outFile = sys_get_temp_dir() . '/wt-swoole-' . uniqid() . '.txt';
        $result = null;

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            \Swoole\Coroutine\run(function () use ($outFile, &$result): void {
                // AdaptiveEngine detects the active runtime and avoids fragile pipes.
                Thread::bindEngine(new AdaptiveEngine());
                $thread = new Thread(new PayloadProbeTask($outFile));
                $thread->start();
                $thread->join();
                $result = is_file($outFile) ? file_get_contents($outFile) : null;
            });
        } finally {
            // Disable runtime hooks (0 flags) so they never leak into other tests.
            \Swoole\Runtime::enableCoroutine(0);
            Thread::bindEngine(new AdaptiveEngine());
        }

        $this->assertSame('ran:none', $result, 'payload must survive SWOOLE_HOOK_ALL intact');
        $this->assertSame(0, $this->zombieChildCount());
        @unlink($outFile);
    }
}
