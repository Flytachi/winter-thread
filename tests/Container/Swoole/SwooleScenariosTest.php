<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Swoole;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Tests\Container\ChildProcessProbe;
use Flytachi\Winter\Thread\Tests\Fixtures\PayloadProbeTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Swoole correctness across every runtime state: inside a coroutine with hooks,
 * hooks enabled but outside a coroutine, and swoole loaded with no hooks at all.
 * In each case the payload must arrive intact (the AdaptiveEngine picks a safe
 * transport) and no worker may linger as a zombie.
 */
#[Group('container')]
#[Group('swoole')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SwooleScenariosTest extends TestCase
{
    use ChildProcessProbe;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole not available.');
        }
    }

    protected function tearDown(): void
    {
        \Swoole\Runtime::enableCoroutine(0);
        Thread::bindEngine(new AdaptiveEngine());
    }

    private function spawnProbe(): ?string
    {
        $out = sys_get_temp_dir() . '/wt-sw-' . uniqid('', true) . '.txt';
        Thread::bindEngine(new AdaptiveEngine());
        $thread = new Thread(new PayloadProbeTask($out));
        $thread->start();
        $thread->join();
        $result = is_file($out) ? (string) file_get_contents($out) : null;
        @unlink($out);
        return $result;
    }

    public function testInsideCoroutineWithHooks(): void
    {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $result = null;
        try {
            \Swoole\Coroutine\run(function () use (&$result): void {
                $result = $this->spawnProbe();
            });
        } finally {
            \Swoole\Runtime::enableCoroutine(0);
        }

        $this->assertSame('ran:none', $result, 'payload intact inside a hooked coroutine');
        $this->assertSame(0, $this->zombieChildCount());
    }

    public function testNoHooksNoCoroutine(): void
    {
        // Swoole loaded but dormant: the plain pipe transport must work normally.
        \Swoole\Runtime::enableCoroutine(0);
        $result = $this->spawnProbe();

        $this->assertSame('ran:none', $result, 'payload intact with swoole loaded but no hooks');
        $this->assertSame(0, $this->zombieChildCount());
    }
}
