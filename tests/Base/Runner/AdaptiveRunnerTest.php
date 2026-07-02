<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Base\Runner;

use Flytachi\Winter\Thread\Payload\ShmTransport;
use Flytachi\Winter\Thread\Runner\AdaptiveRunner;
use Flytachi\Winter\Thread\Tests\Fixtures\PayloadProbeTask;
use PHPUnit\Framework\TestCase;

class AdaptiveRunnerTest extends TestCase
{
    public function testExecutesRunnableFromShmPayload(): void
    {
        if (!extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
        $outFile = sys_get_temp_dir() . '/wt-runner-' . uniqid() . '.txt';
        $runnable = new PayloadProbeTask($outFile);

        // Stage the payload into shm exactly as the launcher would (unsigned here;
        // the runner is constructed with no security provider to match).
        $shm = new ShmTransport();
        $staged = $shm->stage(\Opis\Closure\serialize($runnable));
        $key = $staged->ref;

        $code = (new AdaptiveRunner())->execute(['shmkey' => (string) $key]);

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

        $code = (new AdaptiveRunner(null, $err))
            ->execute(['shmkey' => (string) $staged->ref]);

        $this->assertSame(1, $code);
        rewind($err);
        $this->assertStringContainsString('not a valid Runnable', (string) stream_get_contents($err));
        fclose($err);
    }
}
