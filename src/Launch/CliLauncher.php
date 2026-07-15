<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\LaunchSpec;
use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Payload\StagedPayload;
use Flytachi\Winter\Thread\Payload\TempFileTransport;
use Flytachi\Winter\Thread\ThreadException;
use Opis\Closure\Security\DefaultSecurityProvider;

/**
 * The default parent-side backend: launches a local PHP CLI process with `proc_open`.
 *
 * It bundles everything the parent needs to spawn and trust a worker — the PHP
 * binary path, the `wRunner` bootstrap path, the payload transport, and the
 * optional signing secret — so `Thread` binds a single object instead of an engine.
 *
 * Two ways to build one:
 * - {@see CliLauncher::adaptive()} — self-configuring; the default when nothing is
 *   bound. Detects the CLI/FPM binary, a Swoole-safe transport, and reads
 *   `WINTER_THREAD_SECRET` from the environment.
 * - `new CliLauncher(...)` — explicit; you pass every part yourself.
 *
 * ```
 * // Zero-config — this is already the default.
 * $thread = new Thread(new MyTask());
 *
 * // Explicit:
 * Thread::bindLauncher(new CliLauncher(
 *     binaryPath: '/usr/bin/php',
 *     runnerPath: __DIR__ . '/vendor/flytachi/winter-thread/wRunner',
 *     transport:  new TempFileTransport(),
 *     secret:     'your-signing-secret',
 * ));
 * ```
 *
 * @see Launcher
 */
final readonly class CliLauncher implements Launcher
{
    public function __construct(
        private string $binaryPath,
        private string $runnerPath,
        private PayloadTransport $transport,
        private ?string $secret = null,
    ) {
    }

    /**
     * Self-configuring factory — detects sensible defaults for the current
     * environment, all overridable per argument:
     * - transport: {@see TempFileTransport} under an active Swoole runtime,
     *   otherwise {@see PipeTransport};
     * - binary path: the real PHP CLI binary (correct even under FPM/CGI);
     * - runner path: the packaged `wRunner`;
     * - secret: the explicit argument, else `WINTER_THREAD_SECRET`, else none.
     */
    public static function adaptive(
        ?string $secret = null,
        ?PayloadTransport $transport = null,
        ?string $binaryPath = null,
        ?string $runnerPath = null,
    ): self {
        return new self(
            binaryPath: $binaryPath ?? self::detectBinaryPath(),
            runnerPath: $runnerPath ?? (dirname(__DIR__, 2) . '/wRunner'),
            transport: $transport ?? self::detectTransport(),
            secret: $secret ?? (getenv('WINTER_THREAD_SECRET') ?: null),
        );
    }

    public function security(): ?DefaultSecurityProvider
    {
        return $this->secret !== null ? new DefaultSecurityProvider(secret: $this->secret) : null;
    }

    public function launch(LaunchSpec $spec): ProcessHandle
    {
        $staged = $this->transport->stage($spec->payload);

        $descriptors = [0 => $staged->stdinSpec];
        if ($spec->output !== null) {
            $descriptors[1] = ['file', $spec->output, 'a'];
            $descriptors[2] = ['file', $spec->output, 'a'];
        } else {
            $descriptors[1] = ['pipe', 'w'];
            $descriptors[2] = ['pipe', 'w'];
        }

        $pipes = [];
        $process = proc_open($this->buildCommand($spec, $staged), $descriptors, $pipes, null, $this->childEnv());

        if (!is_resource($process)) {
            $this->transport->cleanup($staged);
            throw new ThreadException('Failed to start the process using proc_open.');
        }

        if ($staged->pipePayload !== null) {
            fwrite($pipes[0], $staged->pipePayload);
            fclose($pipes[0]);
        }
        if ($staged->unlinkAfterOpen !== null) {
            @unlink($staged->unlinkAfterOpen);
        }
        if ($spec->output === null) {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
        }

        $status = proc_get_status($process);
        if (!$status || $status['running'] !== true) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            $this->transport->cleanup($staged);
            throw new ThreadException('Process failed to start or terminated immediately.');
        }

        return new ProcessHandle($process, $pipes, $status['pid'], $this->transport, $staged);
    }

    /**
     * The child's environment — the single place the signing secret becomes an env
     * var (owner-only /proc/<pid>/environ), never argv.
     *
     * - With a secret: inject it so the child builds a matching verifier.
     * - Without one: if the parent carries an ambient WINTER_THREAD_SECRET, blank it
     *   for the child — otherwise the child would build a verifier and reject our
     *   (unsigned) payloads. When there is nothing to override, return null so
     *   proc_open inherits the environment unchanged.
     *
     * @return array<string,string>|null
     */
    private function childEnv(): ?array
    {
        if ($this->secret !== null) {
            return array_merge(getenv(), ['WINTER_THREAD_SECRET' => $this->secret]);
        }
        return getenv('WINTER_THREAD_SECRET') !== false
            ? array_merge(getenv(), ['WINTER_THREAD_SECRET' => ''])
            : null;
    }

    private function buildCommand(LaunchSpec $spec, StagedPayload $staged): string
    {
        $args = [
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
            // Defense-in-depth: escape even though the only current cliArg is
            // --shmkey=<int>. Never let a transport inject into the shell command.
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

        return escapeshellarg($this->binaryPath) . ' '
            . escapeshellarg($this->runnerPath) . ' '
            . implode(' ', $args);
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
