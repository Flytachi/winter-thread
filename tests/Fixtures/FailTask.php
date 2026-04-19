<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

class FailTask implements Runnable
{
    public function run(array $args): void
    {
        throw new \RuntimeException('Intentional failure for testing');
    }
}
