<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

/**
 * Recursively spawns a Thread from inside a Thread: at depth N it launches a
 * child NestingTask(N-1) and folds the child's result into its own. Proves that
 * a background process can itself drive the engine (Thread-inside-Thread).
 */
class NestingTask implements Runnable
{
    public function __construct(
        private int $depth,
        private string $outFile,
    ) {
    }

    public function run(array $args): void
    {
        if ($this->depth <= 0) {
            file_put_contents($this->outFile, 'leaf');
            return;
        }

        $childFile = $this->outFile . '.d' . $this->depth;
        $child = new Thread(new NestingTask($this->depth - 1, $childFile));
        $child->start();
        $child->join();

        $childResult = is_file($childFile) ? (string) file_get_contents($childFile) : 'MISSING';
        @unlink($childFile);

        file_put_contents($this->outFile, 'level' . $this->depth . '->' . $childResult);
    }
}
