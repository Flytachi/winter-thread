<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Base\Runner;

use Flytachi\Winter\Thread\Engine\Engine;
use Flytachi\Winter\Thread\Launch\Launcher;
use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Payload\ShmTransport;
use Flytachi\Winter\Thread\Runner\ProcessRunner;
use Flytachi\Winter\Thread\Runner\Runner;
use Flytachi\Winter\Thread\Tests\Fixtures\PayloadProbeTask;
use PHPUnit\Framework\TestCase;

class ProcessRunnerTest extends TestCase
{
    public function testExecutesRunnableFromShmPayload(): void
    {
        if (!extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
        $outFile = sys_get_temp_dir() . '/wt-runner-' . uniqid() . '.txt';
        $runnable = new PayloadProbeTask($outFile);

        // Stage the payload into shm exactly as the launcher would (Thread signs
        // the Runnable with Opis\Closure before delivery).
        $shm = new ShmTransport();
        $staged = $shm->stage(\Opis\Closure\serialize($runnable));
        $key = $staged->ref;

        $engine = $this->engineWithoutSecurity();
        $code = (new ProcessRunner($engine))->execute(['shmkey' => (string) $key]);

        $this->assertSame(0, $code);
        $this->assertSame('ran:none', file_get_contents($outFile));
        unlink($outFile);
    }

    public function testReturnsOneOnInvalidPayload(): void
    {
        if (!extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
        $shm = new ShmTransport();
        $staged = $shm->stage(serialize(['not', 'a', 'runnable']));
        $err = fopen('php://memory', 'w+');

        $code = (new ProcessRunner($this->engineWithoutSecurity(), $err))
            ->execute(['shmkey' => (string) $staged->ref]);

        $this->assertSame(1, $code);
        rewind($err);
        $this->assertStringContainsString('not a valid Runnable', (string) stream_get_contents($err));
        fclose($err);
    }

    private function engineWithoutSecurity(): Engine
    {
        return new class implements Engine {
            public function transport(): PayloadTransport { throw new \LogicException('unused'); }
            public function launcher(): Launcher { throw new \LogicException('unused'); }
            public function runner(): Runner { throw new \LogicException('unused'); }
            public function binaryPath(): string { return 'php'; }
            public function runnerPath(): string { return 'wRunner'; }
            public function security(): ?\Opis\Closure\Security\DefaultSecurityProvider { return null; }
        };
    }
}
