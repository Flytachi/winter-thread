<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Leak;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Engine\ManualEngine;
use Flytachi\Winter\Thread\Payload\ShmTransport;
use Flytachi\Winter\Thread\Runner\AdaptiveRunner;
use Flytachi\Winter\Thread\Tests\Fixtures\MarkedSleepTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Security guarantees: the payload never leaks into process arguments, and a
 * forged (unsigned) payload is rejected when a serialization secret is set.
 */
#[Group('container')]
#[Group('leak')]
class SecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        Thread::bindEngine(new AdaptiveEngine());
    }

    public function testPayloadNotExposedInProcessCmdline(): void
    {
        if (!is_dir('/proc')) {
            $this->markTestSkipped('requires /proc (Linux container)');
        }

        $marker = 'WT_MARKER_' . bin2hex(random_bytes(6));
        $thread = new Thread(new MarkedSleepTask($marker, 3));
        $pid = $thread->start();

        $cmdline = str_replace("\0", ' ', (string) @file_get_contents("/proc/{$pid}/cmdline"));

        $thread->kill();
        $thread->join();

        $this->assertStringNotContainsString(
            $marker,
            $cmdline,
            'serialized payload must travel via pipe/file/shm, never argv',
        );
    }

    public function testUnsignedPayloadRejectedWhenSecretConfigured(): void
    {
        if (!extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }

        $engine = (new ManualEngine())
            ->withTransport(new ShmTransport())
            ->withBinaryPath('php')
            ->withRunnerPath('wRunner')
            ->withSecurity('the-secret');

        // Forge an UNSIGNED payload (serialized without the security provider).
        $shm = new ShmTransport();
        $staged = $shm->stage(\Opis\Closure\serialize(new MarkedSleepTask('x', 0)));

        $err = fopen('php://memory', 'w+');
        $code = (new AdaptiveRunner($engine->security(), $err))->execute(['shmkey' => (string) $staged->ref]);

        $this->assertSame(1, $code, 'unsigned payload must be rejected under a configured secret');
        rewind($err);
        $this->assertStringContainsString('deserialize', (string) stream_get_contents($err));
        fclose($err);
    }
}
