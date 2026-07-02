<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

/**
 * IO-bound workload: writes $count files of 100 bytes into $dir, reads them all
 * back, and reports the total bytes read (deterministic: $count * 100).
 */
class FileIoTask implements Runnable
{
    public function __construct(
        private string $dir,
        private int $count,
        private string $outFile,
    ) {
    }

    public function run(array $args): void
    {
        for ($i = 0; $i < $this->count; $i++) {
            file_put_contents($this->dir . '/f' . $i . '.txt', str_repeat('x', 100));
        }

        $total = 0;
        for ($i = 0; $i < $this->count; $i++) {
            $path = $this->dir . '/f' . $i . '.txt';
            $total += strlen((string) file_get_contents($path));
            @unlink($path);
        }

        file_put_contents($this->outFile, (string) $total);
    }
}
