<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

/**
 * Writes its own PID to a file, then stays alive briefly so a test can inspect
 * the worker's parent PID (used to prove detached reparenting to init).
 */
class PidReportTask implements Runnable
{
    public function __construct(private string $pidFile)
    {
    }

    public function run(array $args): void
    {
        file_put_contents($this->pidFile, (string) getmypid());
        sleep(2);
    }
}
