<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\LaunchSpec;
use Opis\Closure\Security\DefaultSecurityProvider;

/**
 * Picks the right backend for each launch: {@see SwooleLauncher} when a Swoole
 * runtime is active (a coroutine or enabled hooks — where `proc_open` corrupts the
 * reactor's descriptors), otherwise the default {@see CliLauncher}.
 *
 * The choice is made per `launch()`, not once at bind time, because the same
 * process legitimately launches tasks from both contexts — a plain CLI command and,
 * later, a coroutine inside an HTTP worker. Binding this launcher makes background
 * tasks work in both without the caller knowing which mechanism ran.
 *
 * @see CliLauncher
 * @see SwooleLauncher
 */
final readonly class AdaptiveLauncher implements Launcher
{
    public function __construct(
        private CliLauncher $cli,
        private SwooleLauncher $swoole,
    ) {
    }

    public static function adaptive(
        ?string $secret = null,
        ?string $binaryPath = null,
        ?string $runnerPath = null,
    ): self {
        return new self(
            CliLauncher::adaptive(secret: $secret, binaryPath: $binaryPath, runnerPath: $runnerPath),
            SwooleLauncher::adaptive(secret: $secret, binaryPath: $binaryPath, runnerPath: $runnerPath),
        );
    }

    public function launch(LaunchSpec $spec): ProcessHandle
    {
        return $this->backend()->launch($spec);
    }

    public function security(): ?DefaultSecurityProvider
    {
        return $this->backend()->security();
    }

    private function backend(): Launcher
    {
        return self::swooleRuntimeActive() ? $this->swoole : $this->cli;
    }

    /**
     * True inside a coroutine, or when Swoole runtime hooks are enabled — both make
     * `proc_open` unsafe against the running reactor.
     */
    private static function swooleRuntimeActive(): bool
    {
        if (!extension_loaded('swoole')) {
            return false;
        }
        if (class_exists('\Swoole\Coroutine') && \Swoole\Coroutine::getCid() !== -1) {
            return true;
        }
        if (class_exists('\Swoole\Runtime') && method_exists('\Swoole\Runtime', 'getHookFlags')) {
            return \Swoole\Runtime::getHookFlags() !== 0;
        }
        return false;
    }
}
