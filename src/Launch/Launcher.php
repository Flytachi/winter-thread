<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\LaunchSpec;
use Flytachi\Winter\Thread\ThreadException;
use Opis\Closure\Security\DefaultSecurityProvider;

/**
 * Parent-side backend: turns a {@see LaunchSpec} into a running process, and owns
 * the parent↔child trust contract (the payload-signing secret).
 *
 * The `Launcher` is the single seam for *where and how* a task process is started
 * and how its payload is signed. It is bound once at bootstrap via
 * {@see \Flytachi\Winter\Thread\Thread::bindLauncher()}; when nothing is bound,
 * {@see CliLauncher::adaptive()} is used and self-configures for the current
 * environment (CLI / FPM / Swoole).
 *
 * The default {@see CliLauncher} uses `proc_open` to run a local PHP CLI process,
 * but the interface deliberately hides that: a custom implementation could launch
 * over SSH, inside a Docker container, or on a remote node without any change to
 * {@see \Flytachi\Winter\Thread\Thread}.
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
     * @throws ThreadException If the process fails to start.
     */
    public function launch(LaunchSpec $spec): ProcessHandle;

    /**
     * The Opis\Closure security provider used to sign the serialized payload
     * (parent side) and verify it (child side), or `null` when no secret is
     * configured.
     *
     * Signing is opt-in: with a secret set, forged or tampered payloads are
     * rejected in the child before any object is built. With no secret the payload
     * still goes through opis/closure — never native `unserialize()` — but without
     * verification, so the trust boundary falls back to the private delivery channel.
     * A custom launcher that does not sign simply returns `null`.
     */
    public function security(): ?DefaultSecurityProvider;
}
