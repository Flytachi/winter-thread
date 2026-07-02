<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Payload;

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

    public function testRoundTrip(): void
    {
        $t = new ShmTransport();
        $staged = $t->stage('SHM-PAYLOAD');

        $this->assertSame(['file', '/dev/null', 'r'], $staged->stdinSpec);
        $this->assertMatchesRegularExpression('/^--shmkey=\d+$/', $staged->cliArgs[0]);
        $key = $staged->ref;

        // child side: receive reads and deletes the segment
        $got = $t->receive(['shmkey' => (string) $key]);
        $this->assertSame('SHM-PAYLOAD', $got);

        // segment gone → cleanup is a safe no-op
        $t->cleanup($staged);
        $this->assertTrue(true);
    }
}
