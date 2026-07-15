<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Payload;

/**
 * Parent-side strategy for delivering a serialized task payload to the child.
 *
 * A transport prepares the payload for `proc_open` and cleans up afterwards; it is
 * purely a parent concern. The child does not use a transport — it simply reads
 * whatever the parent staged: STDIN for the pipe/temp-file deliveries, or a
 * shared-memory segment named by `--shmkey` (see
 * {@see \Flytachi\Winter\Thread\Runner\AdaptiveRunner}).
 *
 * Three implementations ship with the library:
 * - {@see PipeTransport}     — payload via a stdin pipe (default in CLI).
 * - {@see TempFileTransport} — payload via a temp file on stdin (Swoole-safe).
 * - {@see ShmTransport}      — payload via System V shared memory (Swoole-safe; needs ext-shmop).
 *
 * The file/shm transports avoid pipe file descriptors, which Swoole corrupts
 * under `SWOOLE_HOOK_ALL`; {@see \Flytachi\Winter\Thread\Launch\CliLauncher::adaptive()}
 * selects one of them automatically when a Swoole runtime is active.
 *
 * @see StagedPayload
 * @see PipeTransport
 * @see TempFileTransport
 * @see ShmTransport
 */
interface PayloadTransport
{
    /**
     * Prepare the payload for delivery.
     *
     * @param string $payload The serialized Runnable.
     * @return StagedPayload The fd-0 descriptor, extra CLI args, and cleanup handle.
     */
    public function stage(string $payload): StagedPayload;

    /**
     * Release any resources staged by {@see stage()} (temp file or shared-memory
     * segment). Safe to call even if the child already consumed them.
     */
    public function cleanup(StagedPayload $staged): void;
}
