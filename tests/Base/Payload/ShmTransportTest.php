<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Base\Payload;

use Flytachi\Winter\Thread\Payload\ShmTransport;
use PHPUnit\Framework\TestCase;

class ShmTransportTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
    }

    public function testStageAllocatesSegmentAndCleansUp(): void
    {
        $t = new ShmTransport();
        $staged = $t->stage('SHM-PAYLOAD');

        $this->assertSame(['file', '/dev/null', 'r'], $staged->stdinSpec);
        $this->assertMatchesRegularExpression('/^--shmkey=\d+$/', $staged->cliArgs[0]);
        $key = $staged->ref;

        // The segment holds the payload until the child (AdaptiveRunner) reads it;
        // read it back here directly to confirm staging wrote it.
        $shm = shmop_open($key, 'a', 0, 0);
        $this->assertSame('SHM-PAYLOAD', shmop_read($shm, 0, shmop_size($shm)));

        // cleanup deletes the still-present segment without error.
        $t->cleanup($staged);
        $this->assertFalse(@shmop_open($key, 'a', 0, 0));
    }
}
