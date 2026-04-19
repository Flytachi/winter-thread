<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Fixtures;

use Flytachi\Winter\Thread\Runnable;

class ArgsTask implements Runnable
{
    public function __construct(private string $outputFile) {}

    public function run(array $args): void
    {
        $lines = [];
        foreach ($args as $key => $value) {
            $lines[] = $key . '=' . ($value === true ? '1' : (string) $value);
        }
        file_put_contents($this->outputFile, implode("\n", $lines));
    }
}
