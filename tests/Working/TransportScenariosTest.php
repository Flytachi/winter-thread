<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Working;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Payload\ShmTransport;
use Flytachi\Winter\Thread\Payload\TempFileTransport;
use Flytachi\Winter\Thread\Tests\Fixtures\ArgsTask;
use Flytachi\Winter\Thread\Tests\Fixtures\EchoTask;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same end-to-end scenarios exercised across every available payload
 * transport, selected via the engine. Shared-memory rows skip without ext-shmop.
 */
class TransportScenariosTest extends TestCase
{
    protected function tearDown(): void
    {
        Thread::bindEngine(new AdaptiveEngine());
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

    private function bind(string $transportClass, bool $needsShmop): void
    {
        if ($needsShmop && !extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
        Thread::bindEngine(new AdaptiveEngine(transport: new $transportClass()));
    }

    #[DataProvider('transportProvider')]
    public function testStartAndJoin(string $transportClass, bool $needsShmop): void
    {
        $this->bind($transportClass, $needsShmop);
        $thread = new Thread(new SleepTask(0));
        $this->assertGreaterThan(0, $thread->start());
        $this->assertSame(0, $thread->join());
    }

    #[DataProvider('transportProvider')]
    public function testFileOutput(string $transportClass, bool $needsShmop): void
    {
        $this->bind($transportClass, $needsShmop);
        $log = sys_get_temp_dir() . '/wt-scen-' . uniqid() . '.log';
        (new Thread(new EchoTask('scenario-out')))->start(outputTarget: $log);
        usleep(300_000);
        $this->assertStringContainsString('scenario-out', (string) file_get_contents($log));
        unlink($log);
    }

    #[DataProvider('transportProvider')]
    public function testCustomArguments(string $transportClass, bool $needsShmop): void
    {
        $this->bind($transportClass, $needsShmop);
        $out = sys_get_temp_dir() . '/wt-args-' . uniqid() . '.txt';
        $thread = new Thread(new ArgsTask($out));
        $thread->start(['user' => 'alice', 'count' => '3']);
        $thread->join();

        $content = (string) file_get_contents($out);
        $this->assertStringContainsString('user=alice', $content);
        $this->assertStringContainsString('count=3', $content);
        unlink($out);
    }
}
