<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Runner;

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\ThreadException;
use Opis\Closure\Security\DefaultSecurityProvider;

/**
 * The default child-side runner — self-configuring, mirroring its parent-side
 * sibling {@see \Flytachi\Winter\Thread\Launch\CliLauncher}: both expose an
 * `adaptive()` factory that reads the environment, and both stay deliberately
 * independent (the child never references the parent-side launcher).
 *
 * `execute()` reads the delivered payload — from a shared-memory segment when a
 * `--shmkey` option is present, otherwise from STDIN (covering both the pipe and
 * temp-file deliveries) — verifies and deserializes it into a {@see Runnable},
 * optionally daemonizes (detached mode), sets the process title, and runs the
 * task, returning the exit code.
 *
 * Signature verification uses the optional {@see DefaultSecurityProvider}; build
 * one from the same secret the parent signed with (or use {@see adaptive()}, which
 * reads `WINTER_THREAD_SECRET`). `null` means the payload is unsigned.
 *
 * @see Runner
 */
final readonly class AdaptiveRunner implements Runner
{
    /**
     * @param DefaultSecurityProvider|null $security  Verifies the payload signature — construct it
     *                                                 from the same secret the parent signed with;
     *                                                 null means the payload is unsigned.
     * @param resource|null                $errStream Where diagnostics are written; defaults to
     *                                                 STDERR. Injectable so tests can capture output.
     */
    public function __construct(
        private ?DefaultSecurityProvider $security = null,
        private mixed $errStream = null,
    ) {
    }

    /**
     * Self-configuring factory — the child-side mirror of
     * {@see \Flytachi\Winter\Thread\Launch\CliLauncher::adaptive()}. Builds the
     * signature verifier from `WINTER_THREAD_SECRET` (owner-only environment, set
     * by the parent launcher), or none when unset/blanked.
     */
    public static function adaptive(): self
    {
        $secret = getenv('WINTER_THREAD_SECRET') ?: null;
        return new self($secret !== null ? new DefaultSecurityProvider(secret: $secret) : null);
    }

    private function stderr(): mixed
    {
        return $this->errStream ?? STDERR;
    }

    public function execute(array $options): int
    {
        try {
            $payload = $this->receive($options);
        } catch (\Throwable $e) {
            fwrite($this->stderr(), 'Error: ' . $e->getMessage() . "\n");
            return 1;
        }
        if ($payload === '') {
            fwrite($this->stderr(), "Error: No payload received.\n");
            return 1;
        }

        // opis/closure is a hard dependency, so deserialization always goes through
        // it — never native unserialize(). With a configured secret it verifies the
        // HMAC signature, rejecting forged/tampered payloads (guards Object Injection).
        try {
            $runnable = \Opis\Closure\unserialize($payload, $this->security);
        } catch (\Throwable $e) {
            // Includes Opis SecurityException for unsigned/tampered payloads when a
            // secret is configured — reject cleanly instead of a fatal error.
            fwrite($this->stderr(), 'Error: failed to deserialize payload: ' . $e->getMessage() . "\n");
            return 1;
        }

        // The serialized string is no longer needed once the object is rebuilt;
        // free it before running the task (matters for large payloads).
        unset($payload);

        if (!$runnable instanceof Runnable) {
            fwrite($this->stderr(), "Error: The provided payload is not a valid Runnable object.\n");
            return 1;
        }

        if (isset($options['detach'])) {
            $this->daemonize();
        }

        $this->setProcessTitle($options, $runnable);

        try {
            $runnable->run($this->parseArgs());
            return 0;
        } catch (\Throwable $e) {
            fwrite($this->stderr(), 'Uncaught exception in background process: ' . $e->getMessage() . "\n");
            fwrite($this->stderr(), $e->getTraceAsString() . "\n");
            return 1;
        }
    }

    /**
     * Read the delivered payload — the child-side counterpart to the parent's
     * transport staging. A `--shmkey` option means a shared-memory segment;
     * otherwise the payload is on STDIN (pipe and temp-file deliveries are
     * identical from the child's view).
     */
    private function receive(array $options): string
    {
        if (isset($options['shmkey'])) {
            return $this->receiveShm((int) $options['shmkey']);
        }
        return (string) stream_get_contents(STDIN);
    }

    /** Read-and-delete the shared-memory segment the parent's ShmTransport allocated. */
    private function receiveShm(int $key): string
    {
        if (!extension_loaded('shmop')) {
            throw new ThreadException('shmkey payload requires ext-shmop.');
        }
        $shm = @shmop_open($key, 'a', 0, 0);
        if ($shm === false) {
            throw new ThreadException("Failed to open shared memory segment (key={$key}).");
        }
        $payload = shmop_read($shm, 0, shmop_size($shm));
        shmop_delete($shm);
        return $payload;
    }

    private function daemonize(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite($this->stderr(), "Error: fork failed for detached mode.\n");
            exit(1);
        }
        if ($pid > 0) {
            // Launcher process L: exit immediately so the parent reaps it cheaply.
            exit(0);
        }
        // Worker process W: new session, no controlling terminal, reparented to init.
        if (posix_setsid() === -1) {
            fwrite($this->stderr(), "Error: setsid failed for detached mode.\n");
            exit(1);
        }
    }

    private function setProcessTitle(array $options, Runnable $runnable): void
    {
        if (!function_exists('cli_set_process_title')) {
            return;
        }
        $namespace = isset($options['namespace']) ? ($options['namespace'] . ' ') : '';
        $tag = $options['tag'] ?? 'runnable';
        if (isset($options['name'])) {
            $name = $options['name'];
        } else {
            $class = get_class($runnable);
            $pos = strrpos($class, '\\');
            $name = $pos === false ? $class : substr($class, $pos + 1);
        }
        cli_set_process_title("WinterThread {$namespace}-> {$name}@{$tag}");
    }

    /** @return array<string, string|bool> */
    private function parseArgs(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        $args = [];
        foreach ($argv as $arg) {
            if (!is_string($arg) || !str_starts_with($arg, '--arg-')) {
                continue;
            }
            $content = substr($arg, 6);
            if (str_contains($content, '=')) {
                [$key, $value] = explode('=', $content, 2);
                $args[$key] = $value;
            } else {
                $args[$content] = true;
            }
        }
        return $args;
    }
}
