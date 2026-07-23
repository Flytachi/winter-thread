<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\LaunchSpec;
use Flytachi\Winter\Thread\Payload\StagedPayload;
use Opis\Closure\Security\DefaultSecurityProvider;

/**
 * Behaviour shared by the launchers that actually start the runner process
 * ({@see CliLauncher} and {@see SwooleLauncher}): signing, the runner's argument
 * list, the secret's environment value, and the default binary / runner paths.
 *
 * Both spawn the same runner in the same way and differ only in the transport
 * used to hand it over (`proc_open` pipes/files vs. a shell background job), so
 * everything up to that point lives here. The using class must declare
 * `private ?string $secret`, `string $binaryPath` and `string $runnerPath`.
 */
trait LauncherSupport
{
    public function security(): ?DefaultSecurityProvider
    {
        return $this->secret !== null ? new DefaultSecurityProvider(secret: $this->secret) : null;
    }

    /**
     * The runner's argument list as raw argv elements — no shell escaping, so it
     * feeds both a shell command (escaped by the caller) and a direct exec.
     *
     * @return list<string>
     */
    protected function buildArgv(LaunchSpec $spec, StagedPayload $staged): array
    {
        $args = [
            '--namespace=' . $spec->namespace,
            '--name=' . $spec->name,
        ];
        if ($spec->tag !== null) {
            $args[] = '--tag=' . $spec->tag;
        }
        if ($spec->debug) {
            $args[] = '--debug';
        }
        if ($spec->detached) {
            $args[] = '--detach';
        }
        foreach ($staged->cliArgs as $cliArg) {
            $args[] = $cliArg;   // e.g. --shmkey=123
        }
        foreach ($spec->arguments as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            if ($value === true) {
                $args[] = '--arg-' . $key;
            } elseif ($value !== null && $value !== false) {
                $args[] = '--arg-' . $key . '=' . $value;
            }
        }

        return $args;
    }

    /**
     * The value to give the child's WINTER_THREAD_SECRET, or null when nothing
     * needs to change:
     * - a configured secret, so the child builds a matching verifier;
     * - an empty string to blank an ambient secret, so an unsigned payload is not
     *   rejected by the child;
     * - null when there is nothing to override.
     */
    protected function secretEnvValue(): ?string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }
        return getenv('WINTER_THREAD_SECRET') !== false ? '' : null;
    }

    private static function detectBinaryPath(): string
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            return PHP_BINARY ?: 'php';
        }
        $candidate = PHP_BINDIR . '/php';
        return is_executable($candidate) ? $candidate : 'php';
    }

    private static function defaultRunnerPath(): string
    {
        return dirname(__DIR__, 2) . '/wRunner';
    }
}
