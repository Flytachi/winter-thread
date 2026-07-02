<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Payload;

/**
 * The result of {@see PayloadTransport::stage()} — how a staged payload is wired
 * into `proc_open` and, later, cleaned up.
 *
 * It is a small data carrier the launcher reads generically: it opens fd 0 from
 * `stdinSpec`, appends `cliArgs` to the command, writes `pipePayload` to the pipe
 * (pipe transport only), unlinks `unlinkAfterOpen` after the process starts
 * (temp-file transport only), and passes `ref` back to
 * {@see PayloadTransport::cleanup()} for teardown.
 *
 * @see PayloadTransport
 */
final readonly class StagedPayload
{
    /**
     * @param array<int, string> $stdinSpec       proc_open descriptor for fd 0 (e.g. `['pipe', 'r']`).
     * @param array<int, string> $cliArgs         Extra, already-safe launch arguments (e.g. `--shmkey=123`).
     * @param string|null        $pipePayload     Payload written to the stdin pipe after launch (pipe transport).
     * @param string|null        $unlinkAfterOpen Temp file unlinked once the child holds its fd (temp-file transport).
     * @param mixed              $ref             Opaque cleanup handle (temp path / shm key) for `cleanup()`.
     */
    public function __construct(
        public array $stdinSpec,
        public array $cliArgs = [],
        public ?string $pipePayload = null,
        public ?string $unlinkAfterOpen = null,
        public mixed $ref = null,
    ) {
    }
}
