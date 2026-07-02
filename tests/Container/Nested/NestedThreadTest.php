<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Nested;

use Flytachi\Winter\Thread\Tests\Container\ChildProcessProbe;
use Flytachi\Winter\Thread\Tests\Fixtures\NestingTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A Thread whose Runnable spawns another Thread, three process levels deep.
 * Confirms the engine works from inside a spawned process and results propagate
 * back up, with no zombies left at any level.
 */
#[Group('container')]
class NestedThreadTest extends TestCase
{
    use ChildProcessProbe;

    public function testThreadInsideThreadThreeLevels(): void
    {
        $out = sys_get_temp_dir() . '/wt-nest-' . uniqid('', true) . '.txt';

        $thread = new Thread(new NestingTask(2, $out));
        $this->assertGreaterThan(0, $thread->start());
        $this->assertSame(0, $thread->join());

        $this->assertSame('level2->level1->leaf', (string) file_get_contents($out));
        @unlink($out);

        $this->assertSame(0, $this->zombieChildCount());
    }
}
