<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

/**
 * Writes a large, exact volume of output — deliberately more than the ~64 KB OS
 * pipe buffer — to exercise pipe draining in join()/reap(). Emits exactly $bytes
 * copies of a single marker character ('A' on STDOUT, 'E' on STDERR) so a test can
 * assert completeness with substr_count().
 */
class FloodTask implements Runnable
{
    public function __construct(
        private int $bytes,
        private bool $toStderr = false,
    ) {
    }

    public function run(array $args): void
    {
        $char = $this->toStderr ? 'E' : 'A';
        $stream = $this->toStderr ? STDERR : STDOUT;

        $chunkSize = 8192;
        $chunk = str_repeat($char, $chunkSize);
        $written = 0;
        while ($written < $this->bytes) {
            $take = min($chunkSize, $this->bytes - $written);
            fwrite($stream, $take === $chunkSize ? $chunk : substr($chunk, 0, $take));
            $written += $take;
        }
    }
}
