<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

/**
 * Compute-bound batch workload: sums 1..n and writes the total. Deterministic.
 */
class BatchSumTask implements Runnable
{
    public function __construct(
        private int $n,
        private string $outFile,
    ) {
    }

    public function run(array $args): void
    {
        $sum = 0;
        for ($i = 1; $i <= $this->n; $i++) {
            $sum += $i;
        }
        file_put_contents($this->outFile, (string) $sum);
    }
}
