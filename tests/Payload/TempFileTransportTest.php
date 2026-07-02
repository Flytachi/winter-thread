<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Payload;

use Flytachi\Winter\Thread\Payload\TempFileTransport;
use PHPUnit\Framework\TestCase;

class TempFileTransportTest extends TestCase
{
    public function testStageWritesFileAndCleanupRemovesIt(): void
    {
        $t = new TempFileTransport();
        $staged = $t->stage('PAYLOAD-DATA');

        $this->assertSame('file', $staged->stdinSpec[0]);
        $path = $staged->stdinSpec[1];
        $this->assertSame('r', $staged->stdinSpec[2]);
        $this->assertSame($path, $staged->unlinkAfterOpen);
        $this->assertFileExists($path);
        $this->assertSame('PAYLOAD-DATA', file_get_contents($path));

        $t->cleanup($staged);
        $this->assertFileDoesNotExist($path);
    }
}
