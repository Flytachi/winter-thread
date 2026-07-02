<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Payload\StagedPayload;

final class ProcessHandle
{
    private ?int $exitCode = null;
    private bool $detached = false;

    /** @param resource $process */
    public function __construct(
        private mixed $process,
        private array $pipes,
        private readonly int $pid,
        private readonly PayloadTransport $transport,
        private readonly StagedPayload $staged,
    ) {}

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
        return isset($this->pipes[1]) && is_resource($this->pipes[1])
            ? (string) stream_get_contents($this->pipes[1])
            : '';
    }

    public function readError(): string
    {
        return isset($this->pipes[2]) && is_resource($this->pipes[2])
            ? (string) stream_get_contents($this->pipes[2])
            : '';
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
        $this->exitCode = $exitCode;
        $this->closePipes();
        proc_close($this->process);
        $this->process = null;
        $this->transport->cleanup($this->staged);
        return $exitCode;
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
