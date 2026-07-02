<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Base;

use Flytachi\Winter\Thread\LaunchSpec;
use PHPUnit\Framework\TestCase;

class LaunchSpecTest extends TestCase
{
    public function testDefaults(): void
    {
        $spec = new LaunchSpec(payload: 'P');
        $this->assertSame('P', $spec->payload);
        $this->assertSame('', $spec->namespace);
        $this->assertSame('anonymous', $spec->name);
        $this->assertNull($spec->tag);
        $this->assertSame([], $spec->arguments);
        $this->assertFalse($spec->debug);
        $this->assertSame('/dev/null', $spec->output);
        $this->assertFalse($spec->detached);
    }

    public function testAllFields(): void
    {
        $spec = new LaunchSpec('P', 'ns', 'Job', 't1', ['a' => 'b'], true, null, true);
        $this->assertSame('ns', $spec->namespace);
        $this->assertSame('Job', $spec->name);
        $this->assertSame('t1', $spec->tag);
        $this->assertSame(['a' => 'b'], $spec->arguments);
        $this->assertTrue($spec->debug);
        $this->assertNull($spec->output);
        $this->assertTrue($spec->detached);
    }
}
