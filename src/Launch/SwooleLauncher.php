<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\LaunchSpec;
use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Payload\StagedPayload;
use Flytachi\Winter\Thread\Payload\TempFileTransport;
use Flytachi\Winter\Thread\ThreadException;
use Opis\Closure\Security\DefaultSecurityProvider;

/**
 * A {@see Launcher} that spawns workers with `Swoole\Process` instead of native
 * `proc_open`. Under a Swoole coroutine server, native `proc_open`/`posix_spawn`
 * races the reactor over the process file-descriptor table (deferred socket close
 * ↔ fd reuse), producing intermittent `Bad file descriptor` failures. `Swoole\Process`
 * does its own reactor-aware fork+exec, sidestepping that conflict.
 *
 * Bind it under Swoole; leave the default {@see CliLauncher} everywhere else:
 * ```
 * Thread::bindLauncher(SwooleLauncher::adaptive());   // e.g. in a Swoole worker
 * ```
 *
 * Constraints (by design, for a clean reactor-safe path):
 *  - Payload must arrive via a file/shm transport ({@see TempFileTransport} default,
 *    or {@see ShmTransport}); {@see \Flytachi\Winter\Thread\Payload\PipeTransport}
 *    is rejected (no parent stdin pipe is wired).
 *  - Output must be a file or `/dev/null`; piped output (`outputTarget: null`) is
 *    rejected — reading a child pipe from the reactor is exactly what we avoid.
 *
 * SKETCH — requires `ext-swoole`, untested outside a Swoole runtime. Validate the
 * reaping model in your environment (see {@see SwooleProcessHandle}). Ships as an
 * optional backend; suggest `ext-swoole` in composer.json.
 *
 * @see Launcher
 * @see SwooleProcessHandle
 */
final readonly class SwooleLauncher implements Launcher
{
    public function __construct(
        private string $binaryPath,
        private string $runnerPath,
        private ?PayloadTransport $transport = null,
        private ?string $secret = null,
    ) {
    }

    /**
     * Self-configuring factory, mirroring {@see CliLauncher::adaptive()}: resolves
     * the PHP binary, the packaged `wRunner`, and the secret from the environment.
     * The transport defaults to {@see TempFileTransport} (a Swoole-safe, pipe-free
     * delivery) unless an explicit one is given.
     */
    public static function adaptive(
        ?string $secret = null,
        ?PayloadTransport $transport = null,
        ?string $binaryPath = null,
        ?string $runnerPath = null,
    ): self {
        return new self(
            binaryPath: $binaryPath ?? (PHP_BINARY ?: 'php'),
            runnerPath: $runnerPath ?? (dirname(__DIR__, 2) . '/wRunner'),
            transport: $transport,
            secret: $secret ?? (getenv('WINTER_THREAD_SECRET') ?: null),
        );
    }

    public function security(): ?DefaultSecurityProvider
    {
        return $this->secret !== null ? new DefaultSecurityProvider(secret: $this->secret) : null;
    }

    public function launch(LaunchSpec $spec): ProcessHandle
    {
        if (!extension_loaded('swoole')) {
            throw new ThreadException('SwooleLauncher requires ext-swoole.');
        }
        if ($spec->output === null) {
            throw new ThreadException(
                'SwooleLauncher does not support piped output (outputTarget: null); use a file or /dev/null.',
            );
        }

        $transport = $this->transport ?? new TempFileTransport();
        $staged = $transport->stage($spec->payload);

        // The child reads its payload from fd 0 as a file (temp file / /dev/null +
        // shmkey). A pipe payload would need a parent stdin pipe — the very thing we
        // avoid under Swoole — so reject it explicitly.
        if ($staged->pipePayload !== null || ($staged->stdinSpec[0] ?? null) !== 'file') {
            $transport->cleanup($staged);
            throw new ThreadException(
                'SwooleLauncher requires a file/shm transport (TempFileTransport or ShmTransport); '
                . 'PipeTransport is unsupported.',
            );
        }

        $command = $this->buildCommand($spec, $staged);
        $secret = $this->childSecret();

        // Swoole's own reactor-aware fork+exec. Redirection is handled by the shell
        // (Swoole\Process::exec has no descriptor map), so no parent pipe fds exist.
        $process = new \Swoole\Process(
            static function (\Swoole\Process $worker) use ($command, $secret): void {
                if ($secret !== null) {
                    putenv('WINTER_THREAD_SECRET=' . $secret);
                }
                $worker->exec('/bin/sh', ['-c', $command]);
            },
            false,
            0,
            false,
        );

        $pid = $process->start();
        if ($pid === false) {
            $transport->cleanup($staged);
            throw new ThreadException('Swoole\Process::start() failed to spawn the worker.');
        }

        return new SwooleProcessHandle($pid, $transport, $staged);
    }

    /**
     * The secret to place in the child's environment: the configured one, or ''
     * to blank an ambient WINTER_THREAD_SECRET when this launcher is unsigned
     * (mirrors {@see CliLauncher}). `null` means leave the environment untouched.
     */
    private function childSecret(): ?string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }
        return getenv('WINTER_THREAD_SECRET') !== false ? '' : null;
    }

    private function buildCommand(LaunchSpec $spec, StagedPayload $staged): string
    {
        $args = [
            escapeshellarg($this->binaryPath),
            escapeshellarg($this->runnerPath),
            '--namespace=' . escapeshellarg($spec->namespace),
            '--name=' . escapeshellarg($spec->name),
        ];
        if ($spec->tag !== null) {
            $args[] = '--tag=' . escapeshellarg($spec->tag);
        }
        if ($spec->debug) {
            $args[] = '--debug';
        }
        if ($spec->detached) {
            $args[] = '--detach';
        }
        foreach ($staged->cliArgs as $cliArg) {
            $args[] = escapeshellarg($cliArg);
        }
        foreach ($spec->arguments as $key => $value) {
            if (!is_scalar($value) && !is_null($value)) {
                continue;
            }
            if ($value === true) {
                $args[] = '--arg-' . escapeshellarg((string) $key);
            } elseif ($value !== null && $value !== false) {
                $args[] = '--arg-' . escapeshellarg((string) $key) . '=' . escapeshellarg((string) $value);
            }
        }

        // fd 0 from the staged file (temp file or /dev/null); fd 1/2 appended to the
        // output target. All arguments are escaped, so the shell is just plumbing.
        $stdin = escapeshellarg($staged->stdinSpec[1]);
        $output = escapeshellarg((string) $spec->output);

        return implode(' ', $args) . ' < ' . $stdin . ' >> ' . $output . ' 2>&1';
    }
}
