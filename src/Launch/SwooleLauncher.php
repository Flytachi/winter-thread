<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Launch;

use Flytachi\Winter\Thread\LaunchSpec;
use Flytachi\Winter\Thread\Payload\PayloadTransport;
use Flytachi\Winter\Thread\Payload\ShmTransport;
use Flytachi\Winter\Thread\Payload\StagedPayload;
use Flytachi\Winter\Thread\Payload\TempFileTransport;
use Flytachi\Winter\Thread\ThreadException;

/**
 * A launcher safe to call from inside a running Swoole reactor.
 *
 * `proc_open` (via `posix_spawn`) inside the reactor corrupts the event loop's
 * file descriptors — the first launch may work, the next fails with "Bad file
 * descriptor". `Swoole\Process` is no help either: Swoole forbids creating one
 * while its async-io threads are up ("unable to create Swoole\Process with
 * async-io threads"). So the runner is started the classic way — through the
 * shell, backgrounded with `&` — using {@see \Swoole\Coroutine\System::exec()},
 * which is non-blocking inside a coroutine and never touches the reactor's fds.
 * {@see AdaptiveLauncher} routes here only under an active Swoole runtime;
 * everywhere else {@see CliLauncher} and `proc_open` are used unchanged.
 *
 * The payload is delivered pipe-free — through shared memory when `ext-shmop` is
 * available, else a temp file read as the child's stdin — never a pipe, which is
 * the fd that conflicts with the reactor. The child's own PID (`$!`) is read back
 * from the shell; the real, daemonized process registers itself in the store.
 *
 * @see AdaptiveLauncher
 * @see SwooleProcessHandle
 */
final readonly class SwooleLauncher implements Launcher
{
    use LauncherSupport;

    public function __construct(
        private string $binaryPath,
        private string $runnerPath,
        private ?string $secret = null,
    ) {
    }

    public static function adaptive(
        ?string $secret = null,
        ?string $binaryPath = null,
        ?string $runnerPath = null,
    ): self {
        return new self(
            binaryPath: $binaryPath ?? self::detectBinaryPath(),
            runnerPath: $runnerPath ?? self::defaultRunnerPath(),
            secret: $secret ?? (getenv('WINTER_THREAD_SECRET') ?: null),
        );
    }

    public function launch(LaunchSpec $spec): ProcessHandle
    {
        if (!extension_loaded('swoole')) {
            throw new ThreadException('SwooleLauncher requires ext-swoole.');
        }

        $transport = $this->pickTransport();
        $staged = $transport->stage($spec->payload);

        // Anything other than a file target becomes /dev/null: a detached task has no
        // parent pipe to write back to under Swoole.
        $output = ($spec->output !== null && $spec->output !== '') ? $spec->output : '/dev/null';
        $stdin = $this->stdinPath($staged);

        // Keep the signing secret in the environment (inherited by the child), never
        // in argv. The value is the framework's single constant, so this is safe.
        $secretValue = $this->secretEnvValue();
        if ($secretValue !== null) {
            putenv('WINTER_THREAD_SECRET=' . $secretValue);
        }

        // php <runner> <args> < <stdin> >> <output> 2>&1 & echo $!
        //  - `&` backgrounds it (the runner then daemonizes itself via --detach);
        //  - `echo $!` prints the child PID, which we read back.
        $command = escapeshellarg($this->binaryPath)
            . ' ' . escapeshellarg($this->runnerPath)
            . ' ' . implode(' ', array_map('escapeshellarg', $this->buildArgv($spec, $staged)))
            . ' < ' . escapeshellarg($stdin)
            . ' >> ' . escapeshellarg($output) . ' 2>&1 & echo $!';

        if (class_exists('\Swoole\Coroutine') && \Swoole\Coroutine::getCid() !== -1) {
            // Non-blocking inside a coroutine; the `&` job outlives this call.
            $result = \Swoole\Coroutine\System::exec($command);
            $out = is_array($result) ? (string) ($result['output'] ?? '') : '';
        } else {
            $out = (string) shell_exec($command);
        }

        $pid = (int) trim($out);

        // Temp-file payload: the shell has opened it for the redirect by now, so it is
        // safe to drop. A shm payload is deleted by the child, so leave it alone.
        if ($stdin !== '/dev/null' && is_file($stdin)) {
            @unlink($stdin);
        }

        return new SwooleProcessHandle($pid);
    }

    /**
     * A pipe-free transport: shared memory when available (RAM, no fd), otherwise a
     * temp file read as the child's stdin. Never {@see PipeTransport} — its pipe fd
     * is exactly what conflicts with the reactor.
     */
    private function pickTransport(): PayloadTransport
    {
        return extension_loaded('shmop') ? new ShmTransport() : new TempFileTransport();
    }

    /**
     * The stdin file for the child: /dev/null for a shm payload (delivered by key),
     * or the staged temp file for a temp-file payload.
     */
    private function stdinPath(StagedPayload $staged): string
    {
        // stdinSpec is ['file', <path>, 'r']; shm uses /dev/null, temp-file uses its path.
        return $staged->stdinSpec[1] ?? '/dev/null';
    }
}
