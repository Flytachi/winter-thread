<?php
declare(strict_types=1);
namespace Flytachi\Winter\Thread\Tests\Base\Launch;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\LaunchSpec;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use PHPUnit\Framework\TestCase;

class CliLauncherTest extends TestCase
{
    private string $stub;

    protected function setUp(): void
    {
        // Stub "runner": reads stdin payload, writes it to the file named by --arg-out.
        $this->stub = sys_get_temp_dir() . '/wt-stub-' . uniqid() . '.php';
        file_put_contents($this->stub, <<<'PHP'
<?php
$opts = getopt('', ['namespace::', 'name::', 'tag::']);
$payload = stream_get_contents(STDIN);
$out = null;
foreach ($_SERVER['argv'] as $a) {
    if (str_starts_with($a, '--arg-out=')) { $out = substr($a, 10); }
}
file_put_contents($out, $payload . '|' . ($opts['name'] ?? '?'));
exit(0);
PHP);
    }

    protected function tearDown(): void
    {
        @unlink($this->stub);
    }

    public function testLaunchRunsRunnerWithPipedPayload(): void
    {
        $outFile = sys_get_temp_dir() . '/wt-cl-' . uniqid() . '.txt';
        $launcher = new CliLauncher(PHP_BINARY, $this->stub, new PipeTransport());
        $spec = new LaunchSpec(payload: 'HELLO', name: 'JobX', arguments: ['out' => $outFile]);

        $handle = $launcher->launch($spec);
        $this->assertGreaterThan(0, $handle->getPid());
        $this->assertSame(0, $handle->join());

        $this->assertSame('HELLO|JobX', file_get_contents($outFile));
        unlink($outFile);
    }
}
