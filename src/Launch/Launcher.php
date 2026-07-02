<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\LaunchSpec;

/**
 * Parent-side spawn strategy: turns a {@see LaunchSpec} into a running process.
 *
 * The `Launcher` is the seam for *where and how* a task process is started. The
 * default {@see CliLauncher} uses `proc_open` to run a local PHP CLI process, but
 * the interface deliberately hides that: a custom implementation could launch
 * over SSH, inside a Docker container, or on a remote node without any change to
 * {@see \Flytachi\Winter\Thread\Thread} or the rest of the engine.
 *
 * A framework building a worker pool consumes the `Launcher` (and the
 * {@see ProcessHandle} it returns) directly, bypassing the heavier `Thread` facade.
 *
 * @see CliLauncher
 * @see ProcessHandle
 */
interface Launcher
{
    /**
     * Launches a process for the given spec and returns a handle to it.
     *
     * @param LaunchSpec $spec Everything needed to start the process.
     * @return ProcessHandle A handle for lifecycle control (wait, reap, signal, read output).
     * @throws \Flytachi\Winter\Thread\ThreadException If the process fails to start.
     */
    public function launch(LaunchSpec $spec): ProcessHandle;
}
