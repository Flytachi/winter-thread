<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

class SleepTask implements Runnable
{
    public function __construct(private int $seconds) {}

    public function run(array $args): void
    {
        sleep($this->seconds);
    }
}
