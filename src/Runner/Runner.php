<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Runner;

/**
 * Child-side execution strategy: runs inside the spawned process.
 *
 * A `Runner` reads the delivered payload, deserializes it into a
 * {@see \Flytachi\Winter\Thread\Runnable}, optionally daemonizes (detached mode),
 * and executes the task — returning the process exit code. It is the child-side
 * counterpart to the parent-side {@see \Flytachi\Winter\Thread\Launch\Launcher}.
 *
 * The default {@see AdaptiveRunner} is driven by the `wRunner` bootstrap script.
 * Implement this interface to customize how the child bootstraps and runs a task.
 *
 * @see AdaptiveRunner
 */
interface Runner
{
    /**
     * Executes the delivered task in the current (child) process.
     *
     * @param array<string, mixed> $options Parsed CLI options passed to the child
     *                                       (e.g. `namespace`, `name`, `tag`,
     *                                       `debug`, `detach`, `shmkey`).
     * @return int The process exit code: 0 on success, non-zero on failure.
     */
    public function execute(array $options): int;
}
