<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Payload\StagedPayload;

/**
 * {@see ProcessHandle} backed by a `Swoole\Process` — the coroutine-safe
 * counterpart to {@see CliProcessHandle}, returned by {@see SwooleLauncher}.
 *
 * It controls the child by PID (no `proc_open` resource): liveness and signals go
 * through `Swoole\Process::kill()`, and reaping through a non-blocking
 * `pcntl_waitpid(WNOHANG)`, so it never fights the Swoole reactor for the fd table
 * the way native `proc_open` does. `join()` polls cooperatively, yielding the
 * reactor via `Swoole\Coroutine::sleep()` when inside a coroutine.
 *
 * SKETCH — validate in a real Swoole runtime. Two environment-specific points:
 *  - Reaping assumes children are NOT auto-reaped by a Swoole SIGCHLD handler. If
 *    your setup installs one, `pcntl_waitpid` returns -1 (already collected) and
 *    the exit code is reported as -1; switch to `Swoole\Process::wait()` then.
 *  - Piped output is not supported by {@see SwooleLauncher}; `readOutput()` /
 *    `readError()` therefore always return ''.
 *
 * @see ProcessHandle
 * @see SwooleLauncher
 */
final class SwooleProcessHandle implements ProcessHandle
{
    private ?int $exitCode = null;
    private bool $detached = false;

    public function __construct(
        private readonly int $pid,
        private readonly PayloadTransport $transport,
        private readonly StagedPayload $staged,
    ) {
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function isAlive(): bool
    {
        if ($this->exitCode !== null || $this->detached) {
            return false;
        }
        // A not-yet-reaped zombie still answers kill(0); reap() collects it.
        return \Swoole\Process::kill($this->pid, 0);
    }

    public function join(int $timeout = 0): ?int
    {
        if ($this->exitCode !== null) {
            return $this->exitCode;
        }
        $start = time();
        while (true) {
            if ($this->reap()) {
                return $this->exitCode ?? -1;
            }
            if ($timeout > 0 && (time() - $start) >= $timeout) {
                return null;
            }
            $this->coSleep(0.05);
        }
    }

    public function reap(): bool
    {
        if ($this->exitCode !== null || $this->detached) {
            return true;
        }
        $status = 0;
        $result = pcntl_waitpid($this->pid, $status, WNOHANG);
        if ($result === 0) {
            return false; // still running
        }
        // $result === pid → exited now; $result === -1 → already gone/reaped elsewhere.
        $code = ($result === $this->pid && pcntl_wifexited($status)) ? pcntl_wexitstatus($status) : -1;
        $this->finish($code);
        return true;
    }

    public function detach(): void
    {
        // Stop tracking without waiting. The child keeps running (in detached mode
        // it is reparented to init, which reaps it). We deliberately do NOT clean up
        // the transport here — the child may still be reading the payload.
        $this->detached = true;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function readOutput(): string
    {
        // SwooleLauncher wires output to a file/dev-null, never a parent pipe.
        return '';
    }

    public function readError(): string
    {
        return '';
    }

    public function signal(int $signal): bool
    {
        return $this->isAlive() && \Swoole\Process::kill($this->pid, $signal);
    }

    public function __destruct()
    {
        if ($this->detached || $this->exitCode !== null) {
            return;
        }
        if (!$this->reap()) {
            $this->detach();
        }
    }

    private function finish(int $exitCode): void
    {
        $this->exitCode = $exitCode;
        $this->transport->cleanup($this->staged);
    }

    /** Cooperative sleep: yields the reactor inside a coroutine, plain usleep otherwise. */
    private function coSleep(float $seconds): void
    {
        if (class_exists('\Swoole\Coroutine') && \Swoole\Coroutine::getCid() !== -1) {
            \Swoole\Coroutine::sleep($seconds);
            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }
}
