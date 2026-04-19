<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

class EchoTask implements Runnable
{
    public function __construct(private string $message) {}

    public function run(array $args): void
    {
        echo $this->message . PHP_EOL;
    }
}
