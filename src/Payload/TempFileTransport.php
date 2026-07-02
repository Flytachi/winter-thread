<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Payload;

use Flytachi\Winter\Thread\ThreadException;

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
