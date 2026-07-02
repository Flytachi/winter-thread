<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests;

use Flytachi\Winter\Thread\Thread;
use Flytachi\Winter\Thread\ThreadException;
use Flytachi\Winter\Thread\Tests\Fixtures\ArgsTask;
use Flytachi\Winter\Thread\Tests\Fixtures\EchoTask;
use Flytachi\Winter\Thread\Tests\Fixtures\FailTask;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use PHPUnit\Framework\TestCase;

class ThreadTest extends TestCase
{
    private string $originalRunnerPath;

    protected function setUp(): void
    {
        $this->originalRunnerPath = Thread::getRunnerScriptPath();
    }

    protected function tearDown(): void
    {
        Thread::bindRunner($this->originalRunnerPath);
        Thread::bindPayloadMode(Thread::PAYLOAD_PIPE);
    }

    // --- Constructor & Getters ---

    public function testConstructorAutoDetectsNameFromClass(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertSame('SleepTask', $thread->getName());
    }

    public function testConstructorDefaults(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertSame('', $thread->getNamespace());
        $this->assertNull($thread->getTag());
        $this->assertNull($thread->getPid());
    }

    public function testConstructorWithAllParams(): void
    {
        $thread = new Thread(new SleepTask(0), 'Workers', 'DataProcessor', 'batch-99');
        $this->assertSame('Workers', $thread->getNamespace());
        $this->assertSame('DataProcessor', $thread->getName());
        $this->assertSame('batch-99', $thread->getTag());
    }

    public function testGetPidIsNullBeforeStart(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertNull($thread->getPid());
    }

    // --- start() ---

    public function testStartReturnsPid(): void
    {
        $thread = new Thread(new SleepTask(0));
        $pid = $thread->start();
        $this->assertGreaterThan(0, $pid);
        $this->assertSame($pid, $thread->getPid());
        $thread->join();
    }

    public function testDefaultOutputTargetIsDevNull(): void
    {
        // Fire-and-forget multiple times — must never produce Broken pipe
        for ($i = 0; $i < 5; $i++) {
            $thread = new Thread(new EchoTask("message $i"));
            $thread->start(); // default: /dev/null
            $thread->join();
        }
        $this->assertTrue(true);
    }

    public function testStartWithFileOutputWritesToFile(): void
    {
        $logFile = sys_get_temp_dir() . '/wt-test-' . uniqid() . '.log';
        $thread = new Thread(new EchoTask('hello from thread'));
        $thread->start(outputTarget: $logFile);
        $thread->join();

        $this->assertFileExists($logFile);
        $this->assertStringContainsString('hello from thread', (string) file_get_contents($logFile));
        unlink($logFile);
    }

    public function testStartWithFileOutputAppendsOnMultipleRuns(): void
    {
        $logFile = sys_get_temp_dir() . '/wt-append-' . uniqid() . '.log';

        (new Thread(new EchoTask('first')))->start(outputTarget: $logFile);
        (new Thread(new EchoTask('second')))->start(outputTarget: $logFile);
        usleep(500_000);

        $content = (string) file_get_contents($logFile);
        $this->assertStringContainsString('first', $content);
        $this->assertStringContainsString('second', $content);
        unlink($logFile);
    }

    public function testStartWithNullOutputCreatesPipe(): void
    {
        $thread = new Thread(new EchoTask('piped output'));
        $thread->start(outputTarget: null);

        $output = '';
        while ($thread->isAlive()) {
            $output .= $thread->readOutput();
            usleep(10_000);
        }
        $output .= $thread->readOutput();
        $thread->join();

        $this->assertStringContainsString('piped output', $output);
    }

    public function testStartWithDebugModeEnablesOutput(): void
    {
        $thread = new Thread(new EchoTask('debug content'));
        $thread->start(debugMode: true, outputTarget: null);

        $output = '';
        while ($thread->isAlive()) {
            $output .= $thread->readOutput();
            usleep(10_000);
        }
        $output .= $thread->readOutput();
        $thread->join();

        $this->assertStringContainsString('debug content', $output);
    }

    // --- join() ---

    public function testJoinReturnsZeroOnSuccess(): void
    {
        $thread = new Thread(new SleepTask(0));
        $thread->start();
        $this->assertSame(0, $thread->join());
    }

    public function testJoinReturnsNonZeroOnTaskException(): void
    {
        $thread = new Thread(new FailTask());
        $thread->start();
        $exitCode = $thread->join();
        $this->assertNotSame(0, $exitCode);
    }

    public function testJoinReturnsMinusOneIfNotStarted(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertSame(-1, $thread->join());
    }

    public function testJoinTimeoutReturnsNull(): void
    {
        $thread = new Thread(new SleepTask(30));
        $thread->start();
        $result = $thread->join(timeout: 1);
        $this->assertNull($result);
        $thread->kill();
    }

    // --- reap() / getExitCode() ---

    public function testReapReturnsFalseWhileRunning(): void
    {
        $thread = new Thread(new SleepTask(30));
        $thread->start();
        $this->assertFalse($thread->reap());
        $this->assertTrue($thread->isAlive());
        $thread->kill();
        $thread->join();
    }

    public function testReapReturnsTrueForNotStarted(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertTrue($thread->reap());
    }

    public function testReapReturnsTrueAfterProcessFinishes(): void
    {
        $thread = new Thread(new SleepTask(0));
        $thread->start();
        // Wait for the child to exit without reaping it.
        while ($thread->isAlive()) {
            usleep(20_000);
        }
        $this->assertTrue($thread->reap());
        $this->assertFalse($thread->isAlive());
    }

    public function testReapCollectsZombie(): void
    {
        $thread = new Thread(new SleepTask(0));
        $pid = $thread->start();

        // Let the child exit. Until reaped it lingers as a zombie (state Z).
        while ($thread->isAlive()) {
            usleep(20_000);
        }

        $thread->reap();

        // After reaping, the PID must no longer exist as a zombie.
        $state = trim((string) shell_exec('ps -o state= -p ' . (int) $pid . ' 2>/dev/null'));
        $this->assertStringStartsNotWith('Z', $state);
    }

    public function testGetExitCodeIsNullBeforeFinish(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertNull($thread->getExitCode());
        $thread->start();
        $this->assertNull($thread->getExitCode());
        $thread->join();
    }

    public function testGetExitCodeAfterJoin(): void
    {
        $thread = new Thread(new SleepTask(0));
        $thread->start();
        $thread->join();
        $this->assertSame(0, $thread->getExitCode());
    }

    public function testGetExitCodeAfterReap(): void
    {
        $thread = new Thread(new FailTask());
        $thread->start();
        while ($thread->isAlive()) {
            usleep(20_000);
        }
        $thread->reap();
        $this->assertNotSame(0, $thread->getExitCode());
        $this->assertNotNull($thread->getExitCode());
    }

    public function testReapInPoolLoopHarvestsFinished(): void
    {
        // Mixed pool: some short, some long. reap() must release the short ones
        // while the long ones keep running, all without blocking.
        $running = [
            new Thread(new SleepTask(0)),
            new Thread(new SleepTask(0)),
            new Thread(new SleepTask(30)),
        ];
        foreach ($running as $t) {
            $t->start();
        }

        // Give the short tasks time to finish.
        usleep(300_000);

        $running = array_values(array_filter($running, fn(Thread $t) => !$t->reap()));

        $this->assertCount(1, $running); // only the long-running one survives
        $running[0]->kill();
        $running[0]->join();
    }

    // --- detach() ---

    public function testDetachStopsTracking(): void
    {
        $thread = new Thread(new SleepTask(1));
        $thread->start();
        $thread->detach();

        // After detaching, the handle is gone: tracking methods report inactive.
        $this->assertFalse($thread->isAlive());
        $this->assertTrue($thread->reap());
        $this->assertFalse($thread->kill());
    }

    public function testDetachOnNotStartedIsNoop(): void
    {
        $thread = new Thread(new SleepTask(0));
        $thread->detach();
        $this->assertFalse($thread->isAlive());
    }

    // --- destructor ---

    public function testDestructorReapsFinishedProcessWithoutZombie(): void
    {
        $thread = new Thread(new SleepTask(0));
        $pid = $thread->start();
        while ($thread->isAlive()) {
            usleep(20_000);
        }

        // Drop the only reference: the destructor must reap the finished child.
        unset($thread);
        gc_collect_cycles();

        $state = trim((string) shell_exec('ps -o state= -p ' . (int) $pid . ' 2>/dev/null'));
        $this->assertStringStartsNotWith('Z', $state);
    }

    // --- isAlive() ---

    public function testIsAliveReturnsFalseBeforeStart(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertFalse($thread->isAlive());
    }

    public function testIsAliveReturnsTrueWhileRunning(): void
    {
        $thread = new Thread(new SleepTask(10));
        $thread->start();
        $this->assertTrue($thread->isAlive());
        $thread->kill();
    }

    public function testIsAliveReturnsFalseAfterJoin(): void
    {
        $thread = new Thread(new SleepTask(0));
        $thread->start();
        $thread->join();
        $this->assertFalse($thread->isAlive());
    }

    // --- Signal control ---

    public function testKillTerminatesProcess(): void
    {
        $thread = new Thread(new SleepTask(30));
        $thread->start();
        $this->assertTrue($thread->isAlive());
        $thread->kill();
        usleep(300_000);
        $this->assertFalse($thread->isAlive());
    }

    public function testKillReturnsFalseIfNotRunning(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertFalse($thread->kill());
    }

    public function testTerminateStopsProcess(): void
    {
        $thread = new Thread(new SleepTask(30));
        $thread->start();
        $this->assertTrue($thread->isAlive());
        $thread->terminate();
        usleep(300_000);
        $this->assertFalse($thread->isAlive());
    }

    public function testTerminateReturnsFalseIfNotRunning(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertFalse($thread->terminate());
    }

    public function testInterruptStopsProcess(): void
    {
        $thread = new Thread(new SleepTask(30));
        $thread->start();
        $this->assertTrue($thread->interrupt());
        usleep(300_000);
        $this->assertFalse($thread->isAlive());
    }

    public function testInterruptReturnsFalseIfNotRunning(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertFalse($thread->interrupt());
    }

    public function testPauseAndResume(): void
    {
        $thread = new Thread(new SleepTask(10));
        $thread->start();
        $this->assertTrue($thread->pause());
        $this->assertTrue($thread->isAlive()); // paused but still in process table
        $this->assertTrue($thread->resume());
        $thread->kill();
    }

    public function testPauseReturnsFalseIfNotRunning(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertFalse($thread->pause());
    }

    public function testResumeReturnsFalseIfNotRunning(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertFalse($thread->resume());
    }

    // --- Custom arguments ---

    public function testCustomArgumentsArePassedToRun(): void
    {
        $outputFile = sys_get_temp_dir() . '/wt-args-' . uniqid() . '.txt';
        $thread = new Thread(new ArgsTask($outputFile));
        $thread->start(['user' => 'alice', 'count' => '3', 'flag' => true]);
        $thread->join();

        $this->assertFileExists($outputFile);
        $content = (string) file_get_contents($outputFile);
        $this->assertStringContainsString('user=alice', $content);
        $this->assertStringContainsString('count=3', $content);
        $this->assertStringContainsString('flag=1', $content);
        unlink($outputFile);
    }

    public function testFalseArgumentsAreSkipped(): void
    {
        $outputFile = sys_get_temp_dir() . '/wt-args-false-' . uniqid() . '.txt';
        $thread = new Thread(new ArgsTask($outputFile));
        $thread->start(['active' => 'yes', 'skip' => false]);
        $thread->join();

        $content = (string) file_get_contents($outputFile);
        $this->assertStringContainsString('active=yes', $content);
        $this->assertStringNotContainsString('skip', $content);
        unlink($outputFile);
    }

    public function testNullArgumentsAreSkipped(): void
    {
        $outputFile = sys_get_temp_dir() . '/wt-args-null-' . uniqid() . '.txt';
        $thread = new Thread(new ArgsTask($outputFile));
        $thread->start(['key' => 'value', 'empty' => null]);
        $thread->join();

        $content = (string) file_get_contents($outputFile);
        $this->assertStringContainsString('key=value', $content);
        $this->assertStringNotContainsString('empty', $content);
        unlink($outputFile);
    }

    // --- readOutput / readError ---

    public function testReadOutputReturnsEmptyWhenOutputGoesToFile(): void
    {
        $logFile = sys_get_temp_dir() . '/wt-ro-' . uniqid() . '.log';
        $thread = new Thread(new EchoTask('something'));
        $thread->start(outputTarget: $logFile);
        $thread->join();
        $this->assertSame('', $thread->readOutput());
        $this->assertSame('', $thread->readError());
        unlink($logFile);
    }

    public function testReadOutputReturnsEmptyWhenDevNull(): void
    {
        $thread = new Thread(new EchoTask('something'));
        $thread->start(); // /dev/null
        $thread->join();
        $this->assertSame('', $thread->readOutput());
        $this->assertSame('', $thread->readError());
    }

    // --- Payload mode ---

    public function testBindPayloadModeWithInvalidModeThrowsException(): void
    {
        $this->expectException(ThreadException::class);
        Thread::bindPayloadMode('invalid_mode');
    }

    public function testBindPayloadModeShmThrowsIfExtensionMissing(): void
    {
        if (extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop is loaded; cannot test missing-extension path.');
        }
        $this->expectException(ThreadException::class);
        Thread::bindPayloadMode(Thread::PAYLOAD_SHM);
    }

    public function testShmPayloadModeStartsAndJoins(): void
    {
        if (!extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
        Thread::bindPayloadMode(Thread::PAYLOAD_SHM);
        $thread = new Thread(new SleepTask(0));
        $pid = $thread->start();
        $this->assertGreaterThan(0, $pid);
        $this->assertSame(0, $thread->join());
    }

    public function testShmPayloadModeIsolatedInLoop(): void
    {
        if (!extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
        Thread::bindPayloadMode(Thread::PAYLOAD_SHM);
        $threads = [];
        for ($i = 0; $i < 5; $i++) {
            $threads[$i] = new Thread(new SleepTask(0));
            $threads[$i]->start();
        }
        foreach ($threads as $thread) {
            $this->assertSame(0, $thread->join());
        }
    }

    public function testShmPayloadModeDeliversCorrectOutput(): void
    {
        if (!extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
        Thread::bindPayloadMode(Thread::PAYLOAD_SHM);
        $logFile = sys_get_temp_dir() . '/wt-shm-' . uniqid() . '.log';
        $thread = new Thread(new EchoTask('shm-payload'));
        $thread->start(outputTarget: $logFile);
        $thread->join();

        $this->assertStringContainsString('shm-payload', (string) file_get_contents($logFile));
        unlink($logFile);
    }

    public function testTempFilePayloadModeStartsAndJoins(): void
    {
        Thread::bindPayloadMode(Thread::PAYLOAD_TEMP_FILE);
        $thread = new Thread(new SleepTask(0));
        $pid = $thread->start();
        $this->assertGreaterThan(0, $pid);
        $this->assertSame(0, $thread->join());
    }

    public function testTempFilePayloadModeIsolatedInLoop(): void
    {
        Thread::bindPayloadMode(Thread::PAYLOAD_TEMP_FILE);
        $threads = [];
        for ($i = 0; $i < 5; $i++) {
            $threads[$i] = new Thread(new SleepTask(0));
            $threads[$i]->start();
        }
        foreach ($threads as $thread) {
            $this->assertSame(0, $thread->join());
        }
    }

    public function testTempFilePayloadModeDeliversCorrectOutput(): void
    {
        Thread::bindPayloadMode(Thread::PAYLOAD_TEMP_FILE);
        $logFile = sys_get_temp_dir() . '/wt-tmpfile-' . uniqid() . '.log';
        $thread = new Thread(new EchoTask('swoole-safe'));
        $thread->start(outputTarget: $logFile);
        $thread->join();

        $this->assertStringContainsString('swoole-safe', (string) file_get_contents($logFile));
        unlink($logFile);
    }

    // --- Static configuration ---

    public function testBindRunnerChangesScriptPath(): void
    {
        Thread::bindRunner('/tmp/custom-runner');
        $this->assertSame('/tmp/custom-runner', Thread::getRunnerScriptPath());
    }

    public function testGetRunnerScriptPathReturnsDefaultWhenNotSet(): void
    {
        $path = Thread::getRunnerScriptPath();
        $this->assertStringEndsWith('wExecutor', $path);
        $this->assertFileExists($path);
    }
}
