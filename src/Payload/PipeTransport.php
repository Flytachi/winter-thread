<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Payload;

/**
 * Delivers the payload through the child's stdin pipe — the default transport in
 * plain CLI. The launcher writes the serialized task to the pipe after spawning;
 * the child reads it from STDIN at startup. Requires no extension.
 *
 * Under Swoole with `SWOOLE_HOOK_ALL` pipe descriptors are corrupted; prefer
 * {@see TempFileTransport} or {@see ShmTransport} there (the AdaptiveEngine
 * switches automatically).
 */
final class PipeTransport implements PayloadTransport
{
    public function stage(string $payload): StagedPayload
    {
        return new StagedPayload(stdinSpec: ['pipe', 'r'], pipePayload: $payload);
    }

    public function receive(array $options): string
    {
        return (string) stream_get_contents(STDIN);
    }

    public function cleanup(StagedPayload $staged): void
    {
    }
}
