<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Bin;

use Flytachi\Winter\Thread\Tests\Fixtures\PayloadProbeTask;
use PHPUnit\Framework\TestCase;

class WRunnerTest extends TestCase
{
    public function testWRunnerExistsAndComposerPointsToIt(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root . '/wRunner');
        $this->assertFileDoesNotExist($root . '/wExecutor');

        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $this->assertSame(['wRunner'], $composer['bin']);
        $this->assertSame('>=8.4', $composer['require']['php']);
    }

    public function testWRunnerExecutesPipedRunnable(): void
    {
        $root = dirname(__DIR__, 2);
        $outFile = sys_get_temp_dir() . '/wt-bin-' . uniqid() . '.txt';
        $runnable = new PayloadProbeTask($outFile);

        $desc = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/wRunner') . ' --name=Test';
        $proc = proc_open($cmd, $desc, $pipes);
        fwrite($pipes[0], \Opis\Closure\serialize($runnable));
        fclose($pipes[0]);
        while (proc_get_status($proc)['running']) {
            usleep(20_000);
        }
        proc_close($proc);

        $this->assertSame('ran:none', file_get_contents($outFile));
        unlink($outFile);
    }
}
