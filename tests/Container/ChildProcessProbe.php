<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container;

/**
 * Shared helper for leak tests: counts direct child processes of the current
 * process that are in the zombie (Z) state.
 */
trait ChildProcessProbe
{
    private function zombieChildCount(): int
    {
        $self = getmypid();
        $out = (string) shell_exec('ps -o ppid=,state= 2>/dev/null');
        $count = 0;
        foreach (explode("\n", trim($out)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 2) {
                continue;
            }
            [$ppid, $state] = $parts;
            if ((int) $ppid === $self && str_starts_with($state, 'Z')) {
                $count++;
            }
        }
        return $count;
    }
}
