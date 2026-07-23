<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

/**
 * {@see ProcessHandle} over a process spawned by {@see SwooleLauncher} via
 * `Swoole\Process`.
 *
 * The launcher is only used for detached, fire-and-forget tasks (the reason to
 * avoid `proc_open` is a running Swoole reactor, which owns long-lived work). A
 * detached child re-parents to init and is no longer this process's to reap, so
 * the handle is intentionally PID-based: liveness and signalling work, but there
 * is nothing to `join()`/`reap()` and no output pipe to read.
 *
 * @see SwooleLauncher
 * @see ProcessHandle
 */
final class SwooleProcessHandle implements ProcessHandle
{
    public function __construct(private readonly int $pid)
    {
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function isAlive(): bool
    {
        // getpgid needs no signalling permission, so this stays correct across users.
        return $this->pid > 0 && posix_getpgid($this->pid) !== false;
    }

    public function join(int $timeout = 0): ?int
    {
        // A detached child belongs to init; it cannot be waited on from here.
        return -1;
    }

    public function reap(): bool
    {
        return true;
    }

    public function detach(): void
    {
        // Already detached — nothing to release.
    }

    public function getExitCode(): ?int
    {
        return null;
    }

    public function readOutput(): string
    {
        return '';
    }

    public function readError(): string
    {
        return '';
    }

    public function signal(int $signal): bool
    {
        return $this->isAlive() && posix_kill($this->pid, $signal);
    }
}
