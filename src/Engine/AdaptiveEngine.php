<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Engine;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Launch\Launcher;
use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Payload\TempFileTransport;
use Flytachi\Winter\Thread\Runner\ProcessRunner;
use Flytachi\Winter\Thread\Runner\Runner;
use Opis\Closure\Security\DefaultSecurityProvider;

/**
 * Self-configuring engine — the default when no engine is bound.
 *
 * At construction it detects the environment and picks sensible defaults, all of
 * which can be overridden through the constructor:
 * - transport: {@see TempFileTransport} when a Swoole runtime is active (coroutine
 *   or hooks enabled), otherwise {@see PipeTransport};
 * - binary path: the real PHP CLI binary (resolves correctly even under FPM/CGI);
 * - runner path: the packaged `wRunner`;
 * - secret: the explicit argument, else the `WINTER_THREAD_SECRET` env var, else none.
 *
 * ```
 * // Zero-config — nothing to do; this is already the default.
 * $thread = new Thread(new MyTask());
 *
 * // Optional overrides:
 * new AdaptiveEngine(secret: 'k', transport: new ShmTransport(), launcher: new MyLauncher());
 * ```
 *
 * @see Engine
 * @see ManualEngine
 */
final readonly class AdaptiveEngine implements Engine
{
    private ?string $secret;
    private PayloadTransport $transport;
    private string $binaryPath;
    private string $runnerPath;
    private ?Launcher $launcher;

    public function __construct(
        ?string $secret = null,
        ?PayloadTransport $transport = null,
        ?string $binaryPath = null,
        ?string $runnerPath = null,
        ?Launcher $launcher = null,
    ) {
        $this->secret = $secret ?? (getenv('WINTER_THREAD_SECRET') ?: null);
        $this->transport = $transport ?? self::detectTransport();
        $this->binaryPath = $binaryPath ?? self::detectBinaryPath();
        $this->runnerPath = $runnerPath ?? (dirname(__DIR__, 2) . '/wRunner');
        $this->launcher = $launcher;
    }

    public function transport(): PayloadTransport
    {
        return $this->transport;
    }

    public function launcher(): Launcher
    {
        // Custom launcher injected → use it as-is (caller owns its wiring).
        // Otherwise build the default CliLauncher from the resolved config.
        return $this->launcher
            ?? new CliLauncher($this->binaryPath, $this->runnerPath, $this->transport, $this->childEnv());
    }

    public function runner(): Runner
    {
        return new ProcessRunner($this);
    }

    public function binaryPath(): string
    {
        return $this->binaryPath;
    }

    public function runnerPath(): string
    {
        return $this->runnerPath;
    }

    public function security(): ?DefaultSecurityProvider
    {
        return $this->secret !== null ? new DefaultSecurityProvider(secret: $this->secret) : null;
    }

    /** @return array<string,string> */
    private function childEnv(): array
    {
        return $this->secret !== null ? ['WINTER_THREAD_SECRET' => $this->secret] : [];
    }

    private static function detectTransport(): PayloadTransport
    {
        if (extension_loaded('swoole') && self::swooleRuntimeActive()) {
            return new TempFileTransport();
        }
        return new PipeTransport();
    }

    /**
     * true if we are inside a coroutine OR Swoole runtime hooks are enabled
     * (both corrupt pipe fds under SWOOLE_HOOK_ALL).
     */
    private static function swooleRuntimeActive(): bool
    {
        if (class_exists('\Swoole\Coroutine') && \Swoole\Coroutine::getCid() !== -1) {
            return true;
        }
        if (class_exists('\Swoole\Runtime') && method_exists('\Swoole\Runtime', 'getHookFlags')) {
            return \Swoole\Runtime::getHookFlags() !== 0;
        }
        return false;
    }

    private static function detectBinaryPath(): string
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            return PHP_BINARY ?: 'php';
        }
        $candidate = PHP_BINDIR . '/php';
        return is_executable($candidate) ? $candidate : 'php';
    }
}
