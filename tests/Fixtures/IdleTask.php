<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

/**
 * Holds a payload blob in memory and idles, so a test can sample the worker
 * process's resident memory (RSS) while it is alive.
 */
class IdleTask implements Runnable
{
    public function __construct(
        private string $blob,
        private int $seconds,
    ) {
    }

    public function run(array $args): void
    {
        $held = $this->blob;
        sleep($this->seconds);
        unset($held);
    }
}
