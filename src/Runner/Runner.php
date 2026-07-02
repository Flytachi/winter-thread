<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Runner;

interface Runner
{
    /** Runs in the child process; returns the process exit code. */
    public function execute(array $options): int;
}
