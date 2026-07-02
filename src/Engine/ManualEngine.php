<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Engine;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Launch\Launcher;
use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\ThreadException;
use Opis\Closure\Security\DefaultSecurityProvider;

/**
 * Explicit, clean-slate engine — configured entirely through immutable withers.
 *
 * Unlike {@see AdaptiveEngine} it detects nothing: each part must be set, and any
 * required part left unset throws {@see \Flytachi\Winter\Thread\ThreadException}
 * when accessed. This makes behavior fully predictable, with no environment magic.
 *
 * ```
 * Thread::bindEngine(
 *     (new ManualEngine())
 *         ->withTransport(new TempFileTransport())
 *         ->withBinaryPath('/usr/bin/php')
 *         ->withRunnerPath(__DIR__ . '/vendor/flytachi/winter-thread/wRunner')
 *         ->withSecurity('your-signing-secret')
 *         ->withLauncher(new MyCustomLauncher()) // optional: custom backend
 * );
 * ```
 *
 * @see Engine
 * @see AdaptiveEngine
 */
final class ManualEngine implements Engine
{
    private ?PayloadTransport $transport = null;
    private ?string $binaryPath = null;
    private ?string $runnerPath = null;
    private ?string $secret = null;
    private ?Launcher $launcher = null;

    public function withTransport(PayloadTransport $transport): static
    {
        $clone = clone $this;
        $clone->transport = $transport;
        return $clone;
    }

    public function withLauncher(Launcher $launcher): static
    {
        $clone = clone $this;
        $clone->launcher = $launcher;
        return $clone;
    }

    public function withBinaryPath(string $binaryPath): static
    {
        $clone = clone $this;
        $clone->binaryPath = $binaryPath;
        return $clone;
    }

    public function withRunnerPath(string $runnerPath): static
    {
        $clone = clone $this;
        $clone->runnerPath = $runnerPath;
        return $clone;
    }

    public function withSecurity(string $secret): static
    {
        $clone = clone $this;
        $clone->secret = $secret;
        return $clone;
    }

    public function transport(): PayloadTransport
    {
        return $this->transport ?? throw new ThreadException('ManualEngine: transport is not configured.');
    }

    public function binaryPath(): string
    {
        return $this->binaryPath ?? throw new ThreadException('ManualEngine: binaryPath is not configured.');
    }

    public function runnerPath(): string
    {
        return $this->runnerPath ?? throw new ThreadException('ManualEngine: runnerPath is not configured.');
    }

    public function security(): ?DefaultSecurityProvider
    {
        return $this->secret !== null ? new DefaultSecurityProvider(secret: $this->secret) : null;
    }

    public function launcher(): Launcher
    {
        // Custom launcher injected → use it as-is (caller owns its wiring), so
        // binaryPath/runnerPath/transport need not be configured in that case.
        if ($this->launcher !== null) {
            return $this->launcher;
        }
        if ($this->secret !== null) {
            $childEnv = ['WINTER_THREAD_SECRET' => $this->secret];
        } else {
            // Neutralize an ambient WINTER_THREAD_SECRET the child would inherit, so
            // unsigned payloads aren't rejected by a stray verifier.
            $childEnv = getenv('WINTER_THREAD_SECRET') !== false ? ['WINTER_THREAD_SECRET' => ''] : [];
        }
        return new CliLauncher($this->binaryPath(), $this->runnerPath(), $this->transport(), $childEnv);
    }
}
