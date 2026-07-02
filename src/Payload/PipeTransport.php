<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Payload;

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
