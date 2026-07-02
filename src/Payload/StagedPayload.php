<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Payload;

final readonly class StagedPayload
{
    public function __construct(
        public array $stdinSpec,
        public array $cliArgs = [],
        public ?string $pipePayload = null,
        public ?string $unlinkAfterOpen = null,
        public mixed $ref = null,
    ) {}
}
