<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Payload;

interface PayloadTransport
{
    /** Parent: prepare the payload; returns fd0 spec + extra CLI args. */
    public function stage(string $payload): StagedPayload;

    /** Child: read the payload back, given the parsed CLI options. */
    public function receive(array $options): string;

    /** Parent: release staged resources (temp file / shm segment). */
    public function cleanup(StagedPayload $staged): void;
}
