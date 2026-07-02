<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Payload;

/**
 * Strategy for delivering a serialized task payload from the parent to the child.
 *
 * The parent and child are separate processes, so a transport works in two
 * cooperating halves:
 * - `stage()` runs in the **parent**: it prepares the payload and returns the
 *   stdin descriptor plus any extra CLI args needed to locate it.
 * - `receive()` runs in the **child**: it reads the payload back.
 *
 * Three implementations ship with the library:
 * - {@see PipeTransport}     — payload via a stdin pipe (default in CLI).
 * - {@see TempFileTransport} — payload via a temp file on stdin (Swoole-safe).
 * - {@see ShmTransport}      — payload via System V shared memory (Swoole-safe; needs ext-shmop).
 *
 * The file/shm transports avoid pipe file descriptors, which Swoole corrupts
 * under `SWOOLE_HOOK_ALL`; {@see \Flytachi\Winter\Thread\Engine\AdaptiveEngine}
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
     * Parent side: prepare the payload for delivery.
     *
     * @param string $payload The serialized Runnable.
     * @return StagedPayload The fd-0 descriptor, extra CLI args, and cleanup handle.
     */
    public function stage(string $payload): StagedPayload;

    /**
     * Child side: read the delivered payload back.
     *
     * @param array<string, mixed> $options Parsed CLI options (e.g. `shmkey`).
     * @return string The serialized Runnable, ready to deserialize.
     */
    public function receive(array $options): string;

    /**
     * Parent side: release any resources staged by {@see stage()} (temp file or
     * shared-memory segment). Safe to call even if the child already cleaned up.
     */
    public function cleanup(StagedPayload $staged): void;
}
