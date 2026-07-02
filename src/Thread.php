<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Engine\Engine;
use Flytachi\Winter\Thread\Launch\ProcessHandle;

/**
 * Manages and controls a single, independent task running in a separate PHP process.
 *
 * This class provides a high-level, Java-like API for creating and managing
 * background processes. It is a thin facade over the configured {@see Engine}, which
 * chooses the payload transport, the launcher, and the child-side runner. By default
 * an {@see AdaptiveEngine} is used, so `new Thread(...)->start()` works out of the box.
 *
 * ---
 * ### Example
 *
 * ```php
 * class MyTask implements Runnable {
 *     public function run(array $args): void { sleep(10); }
 * }
 *
 * $thread = new Thread(new MyTask(), 'Processing', 'VideoTask', 'job-123');
 * $pid = $thread->start();               // fire-and-forget, output -> /dev/null
 * $exitCode = $thread->join();           // wait for completion
 * ```
 *
 * Custom configuration is done once at bootstrap via {@see Thread::bindEngine()}:
 *
 * ```php
 * Thread::bindEngine(
 *     (new ManualEngine())
 *         ->withTransport(new TempFileTransport())
 *         ->withBinaryPath('/usr/bin/php')
 *         ->withSecurity('your-secret')
 * );
 * ```
 *
 * @package Flytachi\Winter\Thread
 * @version 2.0
 * @author Flytachi
 * @see \Flytachi\Winter\Thread\Runnable
 * @see \Flytachi\Winter\Thread\Engine\Engine
 */
final class Thread
{
    private ?ProcessHandle $handle = null;
    private string $name;

    private static ?Engine $engine = null;

    /**
     * @param Runnable    $runnable  The task to execute in the new process.
     * @param string      $namespace A logical grouping for the process.
     * @param string|null $name      Specific name; auto-derived from the Runnable class if null.
     * @param string|null $tag       Optional tag distinguishing this instance.
     */
    public function __construct(
        private readonly Runnable $runnable,
        private readonly string $namespace = '',
        ?string $name = null,
        private readonly ?string $tag = null,
    ) {
        if ($name === null) {
            $reflection = new \ReflectionClass($runnable);
            $this->name = $reflection->isAnonymous() ? 'anonymous' : $reflection->getShortName();
        } else {
            $this->name = $name;
        }
    }

    /**
     * Binds the engine used by all threads. Replaces the default AdaptiveEngine.
     * Call once at application bootstrap.
     */
    public static function bindEngine(Engine $engine): void
    {
        self::$engine = $engine;
    }

    /**
     * Returns the active engine, lazily creating a default AdaptiveEngine if none was bound.
     */
    public static function engine(): Engine
    {
        return self::$engine ??= new AdaptiveEngine();
    }

    /**
     * Starts the Runnable in a new background process.
     *
     * @param array<string, scalar|null> $arguments    Custom per-run arguments (read via getopt as --arg-*).
     * @param bool                       $debugMode    Enable child error reporting.
     * @param string|null                $outputTarget '/dev/null' (default), null (pipe to parent), or a file path.
     * @param bool                       $detached     Daemonize the child (fork + setsid); zombie-free.
     *
     * @return int The PID of the launched process.
     * @throws ThreadException If the process fails to start.
     */
    public function start(
        array $arguments = [],
        bool $debugMode = false,
        ?string $outputTarget = '/dev/null',
        bool $detached = false,
    ): int {
        if ($this->handle !== null && $this->handle->isAlive()) {
            throw new ThreadException(
                'Thread is already running; join()/reap() it or create a new Thread before starting again.',
            );
        }

        $engine = self::engine();
        $spec = new LaunchSpec(
            payload: $this->serialize($engine),
            namespace: $this->namespace,
            name: $this->name,
            tag: $this->tag,
            arguments: $arguments,
            debug: $debugMode,
            output: $outputTarget,
            detached: $detached,
        );
        $this->handle = $engine->launcher()->launch($spec);
        return $this->handle->getPid();
    }

    /**
     * Gets the PID of the child process, or null if not started.
     */
    public function getPid(): ?int
    {
        return $this->handle?->getPid();
    }

    /**
     * Checks whether the child process is currently running.
     */
    public function isAlive(): bool
    {
        return $this->handle?->isAlive() ?? false;
    }

    /**
     * Blocks until the child terminates. Returns its exit code, null on timeout,
     * or -1 if never started.
     */
    public function join(int $timeout = 0): ?int
    {
        // Explicit null-handle check: ProcessHandle::join() legitimately returns
        // null on timeout, which a `?? -1` fallback would wrongly turn into -1.
        if ($this->handle === null) {
            return -1;
        }
        return $this->handle->join($timeout);
    }

    /**
     * Non-blocking reap: collects the child if it has finished (freeing resources
     * and preventing a zombie). Returns true if finished/absent, false if still running.
     */
    public function reap(): bool
    {
        return $this->handle?->reap() ?? true;
    }

    /**
     * Stops tracking the child (non-blocking). See {@see ProcessHandle::detach()}.
     */
    public function detach(): void
    {
        $this->handle?->detach();
    }

    /**
     * Returns the exit code once the process has been reaped, or null otherwise.
     */
    public function getExitCode(): ?int
    {
        return $this->handle?->getExitCode();
    }

    /**
     * Reads STDOUT (only when started with $outputTarget = null).
     */
    public function readOutput(): string
    {
        return $this->handle?->readOutput() ?? '';
    }

    /**
     * Reads STDERR (only when started with $outputTarget = null).
     */
    public function readError(): string
    {
        return $this->handle?->readError() ?? '';
    }

    /**
     * Pauses the child via SIGSTOP.
     */
    public function pause(): bool
    {
        return $this->handle?->signal(SIGSTOP) ?? false;
    }

    /**
     * Resumes a paused child via SIGCONT.
     */
    public function resume(): bool
    {
        return $this->handle?->signal(SIGCONT) ?? false;
    }

    /**
     * Sends SIGINT to the child.
     */
    public function interrupt(): bool
    {
        return $this->handle?->signal(SIGINT) ?? false;
    }

    /**
     * Requests graceful termination via SIGTERM.
     */
    public function terminate(): bool
    {
        return $this->handle?->signal(SIGTERM) ?? false;
    }

    /**
     * Forcefully terminates the child via SIGKILL.
     */
    public function kill(): bool
    {
        return $this->handle?->signal(SIGKILL) ?? false;
    }

    /**
     * Gets the namespace of the process.
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Gets the name of the task.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the optional tag of the process instance.
     */
    public function getTag(): ?string
    {
        return $this->tag;
    }

    private function serialize(Engine $engine): string
    {
        // opis/closure is a hard dependency; a matching signed/unsigned format is
        // produced here and verified by the child (see ProcessRunner).
        return \Opis\Closure\serialize($this->runnable, $engine->security());
    }
}
