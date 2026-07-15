<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Swoole;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Tests\Container\ChildProcessProbe;
use Flytachi\Winter\Thread\Tests\Fixtures\PayloadProbeTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Swoole correctness in both runtime states: inside a coroutine with hooks
 * (SWOOLE_HOOK_ALL) and with swoole loaded but dormant. In each case the payload
 * must arrive intact (CliLauncher::adaptive() picks a safe transport) and no worker
 * may linger as a zombie.
 */
#[Group('container')]
#[Group('swoole')]
class SwooleScenariosTest extends TestCase
{
    use ChildProcessProbe;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole') || !class_exists('\Swoole\Runtime')) {
            $this->markTestSkipped('ext-swoole not available.');
        }
    }

    protected function tearDown(): void
    {
        if (class_exists('\Swoole\Runtime')) {
            \Swoole\Runtime::enableCoroutine(0);
        }
        Thread::bindLauncher(CliLauncher::adaptive());
    }

    private function spawnProbe(): ?string
    {
        $out = sys_get_temp_dir() . '/wt-sw-' . uniqid('', true) . '.txt';
        Thread::bindLauncher(CliLauncher::adaptive());
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
