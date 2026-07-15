<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Payload\PipeTransport;

/**
 * Builds a launcher whose worker is a "lean" PHP: invoked with `-n` (no php.ini),
 * so no swoole / opcache / shared extensions are loaded. Useful for comparing the
 * footprint and throughput of a minimal worker against the default build.
 */
trait LeanWorker
{
    private function leanLauncher(): CliLauncher
    {
        $wrapper = sys_get_temp_dir() . '/wt-lean-php.sh';
        if (!is_file($wrapper)) {
            file_put_contents($wrapper, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . " -n \"\$@\"\n");
            chmod($wrapper, 0755);
        }

        return new CliLauncher(
            binaryPath: $wrapper,
            runnerPath: dirname(__DIR__, 2) . '/wRunner',
            transport: new PipeTransport(),
        );
    }
}
