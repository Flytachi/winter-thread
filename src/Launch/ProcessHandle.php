<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Payload\StagedPayload;

final class ProcessHandle
{
    private ?int $exitCode = null;
    private bool $detached = false;

    /**
     * Buffered STDOUT/STDERR read from the child's pipes. join()/reap() drain the
     * pipes into these buffers so a child that writes more than the OS pipe buffer
     * (~64 KB) can never deadlock on a full pipe while the parent merely waits.
     * readOutput()/readError() consume from here.
     */
    private string $stdoutBuffer = '';
    private string $stderrBuffer = '';

    /** @param resource $process */
    public function __construct(
        private mixed $process,
        private array $pipes,
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
        if (!is_resource($this->process)) {
            return false;
        }
        return proc_get_status($this->process)['running'];
    }

    public function join(int $timeout = 0): ?int
    {
        if (!is_resource($this->process)) {
            return $this->exitCode ?? -1;
        }
        $start = time();
        while (true) {
            // Drain first, every tick: keep the pipe from filling so the child can
            // keep writing and actually reach exit. Without this a bare join() on a
            // child producing > ~64 KB would wait forever on a process that can't
            // finish because its write() is blocked.
            $this->drain();
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                return $this->finish($status['exitcode']);
            }
            if ($timeout > 0 && (time() - $start) >= $timeout) {
                return null;
            }
            usleep(50_000);
        }
    }

    public function reap(): bool
    {
        if (!is_resource($this->process)) {
            return true;
        }
        // Drain on every poll too: a pool that only ever calls reap() (never join())
        // must not let a chatty worker deadlock on a full pipe.
        $this->drain();
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return false;
        }
        $this->finish($status['exitcode']);
        return true;
    }

    public function detach(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        $this->closePipes();
        // No proc_close (it blocks on a live child); no transport cleanup (child owns it).
        $this->process = null;
        $this->detached = true;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function readOutput(): string
    {
        $this->drain();
        $out = $this->stdoutBuffer;
        $this->stdoutBuffer = '';
        return $out;
    }

    public function readError(): string
    {
        $this->drain();
        $err = $this->stderrBuffer;
        $this->stderrBuffer = '';
        return $err;
    }

    public function signal(int $signal): bool
    {
        return $this->isAlive() && posix_kill($this->pid, $signal);
    }

    public function __destruct()
    {
        if ($this->detached || !is_resource($this->process)) {
            return;
        }
        if (!$this->reap()) {
            $this->detach();
        }
    }

    private function finish(int $exitCode): int
    {
        // Final non-blocking drain before the pipes go away, so output written just
        // before exit is captured and remains readable via readOutput() after join().
        $this->drain();
        $this->exitCode = $exitCode;
        $this->closePipes();
        proc_close($this->process);
        $this->process = null;
        $this->transport->cleanup($this->staged);
        return $exitCode;
    }

    /**
     * Pull everything currently available from the STDOUT/STDERR pipes into the
     * buffers. Never blocks: the pipes are forced non-blocking and fread returns
     * '' as soon as no more data is ready. A no-op when output went to a file or
     * /dev/null (no pipes) or after the pipes have been closed.
     */
    private function drain(): void
    {
        foreach ([1 => 'stdoutBuffer', 2 => 'stderrBuffer'] as $index => $buffer) {
            if (!isset($this->pipes[$index]) || !is_resource($this->pipes[$index])) {
                continue;
            }
            stream_set_blocking($this->pipes[$index], false);
            while (($chunk = fread($this->pipes[$index], 65536)) !== false && $chunk !== '') {
                $this->{$buffer} .= $chunk;
            }
        }
    }

    private function closePipes(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
    }
}
