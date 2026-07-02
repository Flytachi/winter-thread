<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread;

final readonly class LaunchSpec
{
    public function __construct(
        public string $payload,
        public string $namespace = '',
        public string $name = 'anonymous',
        public ?string $tag = null,
        public array $arguments = [],
        public bool $debug = false,
        public ?string $output = '/dev/null',
        public bool $detached = false,
    ) {
    }
}
