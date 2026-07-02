<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Payload;

use Flytachi\Winter\Thread\ThreadException;

final class ShmTransport implements PayloadTransport
{
    private static int $seq = 0;

    public function stage(string $payload): StagedPayload
    {
        $size = strlen($payload);
        for ($i = 0; $i < 5; $i++) {
            $key = abs(crc32(uniqid('__wtr_thread_', true) . (++self::$seq)));
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
