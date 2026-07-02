<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Engine;

use Flytachi\Winter\Thread\Launch\Launcher;
use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Opis\Closure\Security\DefaultSecurityProvider;

/**
 * The single configuration/strategy root of the engine.
 *
 * An `Engine` is the **parent-side** configuration: it selects the payload
 * transport and the launcher, and holds the PHP binary path, the `wRunner` script
 * path, and the optional serialization secret. Everything the parent needs to
 * spawn a task is obtained from an `Engine`.
 *
 * Bind one once at application bootstrap via {@see \Flytachi\Winter\Thread\Thread::bindEngine()}.
 * When nothing is bound, {@see AdaptiveEngine} is used and configures itself for
 * the current environment (CLI / FPM / Swoole).
 *
 * Two implementations ship with the library:
 * - {@see AdaptiveEngine} — self-configuring; the default. Zero-config out of the box.
 * - {@see ManualEngine}   — clean-slate; you set every part explicitly via withers.
 *
 * @see AdaptiveEngine
 * @see ManualEngine
 */
interface Engine
{
    /**
     * The payload transport used to deliver a serialized task to the child
     * process (pipe / temp file / shared memory).
     */
    public function transport(): PayloadTransport;

    /**
     * The parent-side launcher that spawns the process and returns a handle.
     * Consumed directly by higher-level primitives such as a worker pool.
     */
    public function launcher(): Launcher;

    /**
     * Absolute path to the PHP CLI binary used to launch worker processes.
     */
    public function binaryPath(): string;

    /**
     * Absolute path to the `wRunner` bootstrap script executed in the child.
     */
    public function runnerPath(): string;

    /**
     * The Opis\Closure security provider used to sign (parent) and verify
     * (child) serialized payloads, or `null` when no secret is configured.
     *
     * When a secret is set, forged or tampered payloads are rejected — the
     * primary defense against PHP object injection.
     */
    public function security(): ?DefaultSecurityProvider;
}
