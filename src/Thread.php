<?php

namespace Flytachi\Winter\Thread;

/**
 * Represents a single, independent thread of execution
 * that runs in a separate process using proc_open.
 */
class Thread
{
    private RunnableInterface $runnable;
    private ?int $pid = null;
    private $process_handle = null;

    /**
     * @param RunnableInterface $runnable The task to be executed.
     */
    public function __construct(RunnableInterface $runnable)
    {
        $this->runnable = $runnable;
    }

    /**
     * Starts the execution of the Runnable task in a new background process.
     *
     * @param string|null $outputFile Optional. A file to redirect the process's standard output and error.
     *                                If null, output is discarded.
     * @return int The Process ID (PID) of the newly created process.
     * @throws ThreadException If the process cannot be started.
     */
    public function start(?string $outputFile = null): int
    {
        $payload = serialize($this->runnable);

        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin - pipe to write to
            1 => ['pipe', 'w'], // stdout - pipe to read from
            2 => ['pipe', 'w'], // stderr
        ];

        if ($outputFile !== null) {
            $descriptorSpec[1] = ['file', $outputFile, 'a']; // append
            $descriptorSpec[2] = ['file', $outputFile, 'a']; // append
        }

        $command = $this->buildCommand();

        $this->process_handle = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($this->process_handle)) {
            throw new ThreadException('Failed to start the process using proc_open.');
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        $status = proc_get_status($this->process_handle);
        if (!$status || $status['running'] !== true) {
            proc_close($this->process_handle);
            throw new ThreadException('Process failed to start or terminated immediately.');
        }

        $this->pid = $status['pid'];
        return $this->pid;
    }

    /**
     * Constructs the command to execute the runner script.
     * @return string
     */
    private function buildCommand(): string
    {
        $php_executable = PHP_BINARY ?: 'php';
        $runnerScript = dirname(__DIR__) . '/runner.php';

        return "{$php_executable} {$runnerScript}";
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    /**
     * Checks if the process is still running.
     * @return bool
     */
    public function isRunning(): bool
    {
        if (!is_resource($this->process_handle)) {
            return false;
        }

        $status = proc_get_status($this->process_handle);
        return $status['running'];
    }

    /**
     * Waits for the process to complete.
     * @return int The exit code of the process.
     */
    public function wait(): int
    {
        if (!$this->isRunning()) {
            return 0; // Процесс уже завершился
        }

        // Блокируем выполнение до завершения процесса
        $status = proc_get_status($this->process_handle);
        while ($status['running']) {
            usleep(50000); // 50ms
            $status = proc_get_status($this->process_handle);
        }

        proc_close($this->process_handle);
        return $status['exitcode'];
    }
}
