<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

/**
 * Parent-side control contract over a launched task process.
 *
 * A {@see Launcher} returns a `ProcessHandle` from {@see Launcher::launch()}; the
 * parent drives the child's whole lifecycle through it — wait, non-blocking reap,
 * detach, signal, and read buffered output. Programming against this interface
 * (rather than a concrete class) is what lets the process **backend** be swapped:
 * the default {@see CliProcessHandle} wraps a `proc_open` resource, but a Swoole /
 * Docker / SSH launcher can return its own implementation without any change to
 * {@see \Flytachi\Winter\Thread\Thread} or a worker pool built on these primitives.
 *
 * @see Launcher
 * @see CliProcessHandle
 */
interface ProcessHandle
{
    /**
     * The OS process id of the launched child.
     */
    public function getPid(): int;

    /**
     * Whether the child is currently running.
     */
    public function isAlive(): bool;

    /**
     * Blocks until the child terminates (draining its output while it waits) and
     * returns the exit code, `null` on timeout, or `-1` if it was never tracked.
     *
     * @param int $timeout Seconds to wait; `0` waits indefinitely.
     */
    public function join(int $timeout = 0): ?int;

    /**
     * Non-blocking harvest: collects the child if it has finished (freeing
     * resources and preventing a zombie). Returns `true` if finished/absent,
     * `false` if still running.
     */
    public function reap(): bool;

    /**
     * Stops tracking the child without waiting for it — the process keeps running,
     * owned elsewhere. No further lifecycle control is possible afterwards.
     */
    public function detach(): void;

    /**
     * The exit code once the process has been reaped, or `null` otherwise.
     */
    public function getExitCode(): ?int;

    /**
     * Consuming read of the child's STDOUT captured since the previous call
     * (only when it was launched with piped output); `''` otherwise.
     */
    public function readOutput(): string;

    /**
     * Consuming read of the child's STDERR captured since the previous call
     * (only when it was launched with piped output); `''` otherwise.
     */
    public function readError(): string;

    /**
     * Sends a POSIX signal to the child. Returns `true` if it was delivered.
     */
    public function signal(int $signal): bool;
}
