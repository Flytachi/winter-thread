<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\LaunchSpec;
use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Payload\StagedPayload;
use Flytachi\Winter\Thread\Payload\TempFileTransport;
use Flytachi\Winter\Thread\ThreadException;

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
    use LauncherSupport;
    use DetectsSwooleRuntime;

    /**
     * @param PayloadTransport|null $transport A fixed transport, or `null` to
     *        auto-detect one per launch (see {@see launch()}). Detection is
     *        deferred to launch time on purpose: the Swoole runtime may be
     *        inactive when the launcher is built (e.g. bound during preload) yet
     *        active when a task is actually started (inside a worker/coroutine).
     */
    public function __construct(
        private string $binaryPath,
        private string $runnerPath,
        private ?PayloadTransport $transport = null,
        private ?string $secret = null,
    ) {
    }

    /**
     * Self-configuring factory — detects sensible defaults for the current
     * environment, all overridable per argument:
     * - transport: left to per-launch auto-detection ({@see TempFileTransport}
     *   under an active Swoole runtime, otherwise {@see PipeTransport}) unless an
     *   explicit one is given;
     * - binary path: the real PHP CLI binary (correct even under FPM/CGI);
     * - runner path: the packaged `wRunner`;
     * - secret: the explicit argument, else `WINTER_THREAD_SECRET`, else none.
     *
     * The binary path and secret are read from the stable environment eagerly; the
     * transport is intentionally NOT resolved here, so binding this launcher during
     * preload (before the Swoole runtime is up) still yields the correct transport
     * once tasks run inside a worker/coroutine.
     */
    public static function adaptive(
        ?string $secret = null,
        ?PayloadTransport $transport = null,
        ?string $binaryPath = null,
        ?string $runnerPath = null,
    ): self {
        return new self(
            binaryPath: $binaryPath ?? self::detectBinaryPath(),
            runnerPath: $runnerPath ?? self::defaultRunnerPath(),
            transport: $transport,
            secret: $secret ?? (getenv('WINTER_THREAD_SECRET') ?: null),
        );
    }

    public function launch(LaunchSpec $spec): ProcessHandle
    {
        // Resolve the transport HERE, not at construction: under Swoole the runtime
        // may only be active (coroutine/hooks up) by the time a task is launched,
        // long after this launcher was built. A null transport means auto-detect.
        $transport = $this->transport ?? self::detectTransport();
        $staged = $transport->stage($spec->payload);

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
            $transport->cleanup($staged);
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
            $transport->cleanup($staged);
            throw new ThreadException('Process failed to start or terminated immediately.');
        }

        return new CliProcessHandle($process, $pipes, $status['pid'], $transport, $staged);
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
        $value = $this->secretEnvValue();
        return $value === null
            ? null   // nothing to override — proc_open inherits the environment unchanged
            : array_merge(getenv(), ['WINTER_THREAD_SECRET' => $value]);
    }

    private function buildCommand(LaunchSpec $spec, StagedPayload $staged): string
    {
        // Escape each raw argv element for the shell; the args themselves are the
        // same list a direct exec would receive (see LauncherSupport::buildArgv()).
        return escapeshellarg($this->binaryPath) . ' '
            . escapeshellarg($this->runnerPath) . ' '
            . implode(' ', array_map('escapeshellarg', $this->buildArgv($spec, $staged)));
    }

    private static function detectTransport(): PayloadTransport
    {
        if (self::swooleRuntimeActive()) {
            return new TempFileTransport();
        }
        return new PipeTransport();
    }
}
