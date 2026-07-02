<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

/**
 * Carries a large payload and reports its length + md5, so a test can prove the
 * transport delivered every byte (and never deadlocked on a full pipe buffer).
 */
class LargePayloadTask implements Runnable
{
    public function __construct(
        private string $data,
        private string $outFile,
    ) {
    }

    public function run(array $args): void
    {
        file_put_contents($this->outFile, strlen($this->data) . ':' . md5($this->data));
    }
}
