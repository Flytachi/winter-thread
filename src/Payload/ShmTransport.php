<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Payload;

use Flytachi\Winter\Thread\ThreadException;

/**
 * Delivers the payload through a System V shared-memory segment (RAM only). The
 * segment is allocated `0600` and its key is passed to the child via `--shmkey`;
 * the child reads and deletes it. Uses no pipe or temp file — safe under Swoole
 * `SWOOLE_HOOK_ALL`, at the cost of requiring `ext-shmop`.
 */
final class ShmTransport implements PayloadTransport
{
    private static int $seq = 0;

    public function stage(string $payload): StagedPayload
    {
        if (!extension_loaded('shmop')) {
            throw new ThreadException('ShmTransport requires ext-shmop.');
        }
        $size = strlen($payload);
        for ($i = 0; $i < 5; $i++) {
            // Mask the sign bit for a non-negative int key on any build (on 32-bit,
            // abs(crc32(...)) could overflow to a float).
            $key = crc32(uniqid('__wtr_thread_', true) . (++self::$seq)) & 0x7fffffff;
            $shm = @shmop_open($key, 'n', 0600, $size);
            if ($shm !== false) {
                shmop_write($shm, $payload, 0);
                return new StagedPayload(
                    stdinSpec: ['file', '/dev/null', 'r'],
                    cliArgs: ['--shmkey=' . $key],
                    ref: $key,
                );
            }
        }
        throw new ThreadException('Failed to allocate shared memory segment.');
    }

    public function receive(array $options): string
    {
        if (!extension_loaded('shmop')) {
            throw new ThreadException('ShmTransport requires ext-shmop.');
        }
        $key = (int) ($options['shmkey'] ?? 0);
        $shm = @shmop_open($key, 'a', 0, 0);
        if ($shm === false) {
            throw new ThreadException("Failed to open shared memory segment (key={$key}).");
        }
        $payload = shmop_read($shm, 0, shmop_size($shm));
        shmop_delete($shm);
        return $payload;
    }

    public function cleanup(StagedPayload $staged): void
    {
        if (!extension_loaded('shmop') || !is_int($staged->ref)) {
            return;
        }
        $shm = @shmop_open($staged->ref, 'a', 0, 0);
        if ($shm !== false) {
            shmop_delete($shm);
        }
    }
}
