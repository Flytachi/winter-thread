<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Leak;

use Flytachi\Winter\Thread\Tests\Container\ChildProcessProbe;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The FPM-style risk: a long-lived parent (a web worker handling many requests)
 * that fires background tasks and never joins them. With detached mode the
 * workers reparent to init, so no zombie ever accumulates under the parent.
 */
#[Group('container')]
#[Group('fpm')]
#[Group('leak')]
class FpmScenarioTest extends TestCase
{
    use ChildProcessProbe;

    public function testLongLivedParentFireAndForgetLeavesNoZombies(): void
    {
        if (!function_exists('posix_setsid')) {
            $this->markTestSkipped('ext-posix not available.');
        }

        // Simulate many "requests", each dispatching a detached background task.
        for ($i = 0; $i < 25; $i++) {
            $thread = new Thread(new SleepTask(0));
            $thread->start(detached: true);
            $thread->join(); // reap only the ephemeral launcher
            unset($thread);
        }

        usleep(400_000);
        $this->assertSame(
            0,
            $this->zombieChildCount(),
            'detached workers must reparent to init, never lingering as our zombies',
        );
    }
}
