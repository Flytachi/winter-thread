<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Base\Payload;

use Flytachi\Winter\Thread\Payload\StagedPayload;
use PHPUnit\Framework\TestCase;

class StagedPayloadTest extends TestCase
{
    public function testHoldsFields(): void
    {
        $s = new StagedPayload(['pipe', 'r'], ['--shmkey=1'], 'PL', '/tmp/x', 42);
        $this->assertSame(['pipe', 'r'], $s->stdinSpec);
        $this->assertSame(['--shmkey=1'], $s->cliArgs);
        $this->assertSame('PL', $s->pipePayload);
        $this->assertSame('/tmp/x', $s->unlinkAfterOpen);
        $this->assertSame(42, $s->ref);
    }

    public function testDefaults(): void
    {
        $s = new StagedPayload(['file', '/dev/null', 'r']);
        $this->assertSame([], $s->cliArgs);
        $this->assertNull($s->pipePayload);
        $this->assertNull($s->unlinkAfterOpen);
        $this->assertNull($s->ref);
    }
}
