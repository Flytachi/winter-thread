<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

/**
 * CPU-bound workload: iterated SHA-256 hashing. Deterministic, so a test can
 * compute the expected digest independently.
 */
class HashTask implements Runnable
{
    public function __construct(
        private string $seed,
        private int $rounds,
        private string $outFile,
    ) {
    }

    public function run(array $args): void
    {
        $h = $this->seed;
        for ($i = 0; $i < $this->rounds; $i++) {
            $h = hash('sha256', $h);
        }
        file_put_contents($this->outFile, $h);
    }
}
