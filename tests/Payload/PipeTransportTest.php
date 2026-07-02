<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Payload;

use Flytachi\Winter\Thread\Payload\PipeTransport;
use PHPUnit\Framework\TestCase;

class PipeTransportTest extends TestCase
{
    public function testStagePutsPayloadOnPipe(): void
    {
        $staged = (new PipeTransport())->stage('SERIALIZED');
        $this->assertSame(['pipe', 'r'], $staged->stdinSpec);
        $this->assertSame('SERIALIZED', $staged->pipePayload);
        $this->assertSame([], $staged->cliArgs);
    }
}
