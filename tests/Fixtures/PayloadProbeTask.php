<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

/**
 * Writes "ran:<tag>" to a file, proving the payload round-tripped and run() executed.
 * A named fixture (not an anonymous class) so it serializes cleanly.
 */
class PayloadProbeTask implements Runnable
{
    public function __construct(private string $file)
    {
    }

    public function run(array $args): void
    {
        file_put_contents($this->file, 'ran:' . ($args['tag'] ?? 'none'));
    }
}
