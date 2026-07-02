<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

/**
 * Carries a distinctive marker string in its serialized payload and stays alive
 * for a few seconds, so a test can inspect the child's /proc/<pid>/cmdline and
 * prove the payload (marker) never leaks into the process arguments.
 */
class MarkedSleepTask implements Runnable
{
    public function __construct(
        private string $marker,
        private int $seconds = 3,
    ) {
    }

    public function run(array $args): void
    {
        // Reference the marker so it cannot be optimized away.
        $seen = $this->marker;
        sleep($this->seconds);
        unset($seen);
    }
}
