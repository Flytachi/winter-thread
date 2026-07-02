<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Payload;

use Flytachi\Winter\Thread\ThreadException;

/**
 * Delivers the payload through a temporary file placed on the child's stdin. The
 * file is created with `0600` permissions and unlinked immediately after the
 * process starts (the child keeps its open fd), so nothing lingers on disk.
 *
 * Uses no pipe file descriptors, which makes it safe under Swoole
 * `SWOOLE_HOOK_ALL`. Requires no extension.
 */
final class TempFileTransport implements PayloadTransport
{
    public function stage(string $payload): StagedPayload
    {
        $path = tempnam(sys_get_temp_dir(), '__wtr_thread_');
        if ($path === false) {
            throw new ThreadException('Failed to create temporary file for payload.');
        }
        chmod($path, 0600);
        if (file_put_contents($path, $payload) === false) {
            @unlink($path);
            throw new ThreadException('Failed to write payload to temporary file.');
        }
        return new StagedPayload(
            stdinSpec: ['file', $path, 'r'],
            unlinkAfterOpen: $path,
            ref: $path,
        );
    }

    public function receive(array $options): string
    {
        return (string) stream_get_contents(STDIN);
    }

    public function cleanup(StagedPayload $staged): void
    {
        if (is_string($staged->ref) && is_file($staged->ref)) {
            @unlink($staged->ref);
        }
    }
}
