<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Payload;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Payload\ShmTransport;
use Flytachi\Winter\Thread\Payload\TempFileTransport;
use Flytachi\Winter\Thread\Tests\Fixtures\LargePayloadTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A payload far larger than a pipe's ~64 KB kernel buffer must be delivered
 * byte-intact by every transport, without deadlocking (the launcher writes the
 * pipe while the child drains STDIN at startup).
 */
#[Group('container')]
class LargePayloadTest extends TestCase
{
    protected function tearDown(): void
    {
        Thread::bindLauncher(CliLauncher::adaptive());
    }

    /** @return array<string, array{class-string, bool}> */
    public static function transportProvider(): array
    {
        return [
            'pipe'     => [PipeTransport::class, false],
            'tempfile' => [TempFileTransport::class, false],
            'shm'      => [ShmTransport::class, true],
        ];
    }

    #[DataProvider('transportProvider')]
    public function testLargePayloadDeliveredIntact(string $transportClass, bool $needsShmop): void
    {
        if ($needsShmop && !extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
        Thread::bindLauncher(CliLauncher::adaptive(transport: new $transportClass()));

        // ~1.4 MB — well beyond a pipe's ~64 KB buffer.
        $data = str_repeat('winter-thread-payload-', 65536);
        $out = sys_get_temp_dir() . '/wt-large-' . uniqid('', true) . '.txt';

        $thread = new Thread(new LargePayloadTask($data, $out));
        $this->assertGreaterThan(0, $thread->start());
        $this->assertSame(0, $thread->join(), 'a large payload must neither deadlock nor fail');

        $this->assertSame(
            strlen($data) . ':' . md5($data),
            (string) file_get_contents($out),
            'the payload must arrive byte-intact',
        );
        @unlink($out);
    }
}
