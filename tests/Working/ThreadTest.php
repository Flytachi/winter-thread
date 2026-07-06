<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Working;

use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Engine\ManualEngine;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Payload\ShmTransport;
use Flytachi\Winter\Thread\Payload\TempFileTransport;
use Flytachi\Winter\Thread\Thread;
use Flytachi\Winter\Thread\Tests\Fixtures\ArgsTask;
use Flytachi\Winter\Thread\Tests\Fixtures\EchoTask;
use Flytachi\Winter\Thread\Tests\Fixtures\FailTask;
use Flytachi\Winter\Thread\Tests\Fixtures\FloodTask;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use PHPUnit\Framework\TestCase;

class ThreadTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset to a fresh default engine so a bindEngine() in any test never leaks.
        Thread::bindEngine(new AdaptiveEngine());
    }

    // --- Constructor & Getters ---

    public function testConstructorAutoDetectsNameFromClass(): void
    {
        $thread = new Thread(new SleepTask(0));
        $this->assertSame('SleepTask', $thread->getName());
    }

    /**
     * Regression: an unsigned engine must not break when the parent environment
     * carries a stray WINTER_THREAD_SECRET (the child would otherwise inherit it,
     * build a verifier, and reject every unsigned payload).
     */
    public function testUnsignedEngineIgnoresAmbientSecret(): void
    {
        $prev = getenv('WINTER_THREAD_SECRET');
        putenv('WINTER_THREAD_SECRET=ambient-leak');
        try {
            $adaptive = new AdaptiveEngine();
            Thread::bindEngine(
                (new ManualEngine())
                    ->withTransport(new PipeTransport())
                    ->withBinaryPath($adaptive->binaryPath())
                    ->withRunnerPath($adaptive->runnerPath())
            );
            $thread = new Thread(new SleepTask(0));
            $thread->start();
            $this->assertSame(0, $thread->join(), 'unsigned task must run despite an ambient secret');
        } finally {
            if ($prev === false) {
                putenv('WINTER_THREAD_SECRET');
            } else {
                putenv("WINTER_THREAD_SECRET={$prev}");
            }
        }
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

    // --- Large piped output: join()/reap() must drain, never deadlock ---

    public function testBareJoinDrainsLargeOutputWithoutDeadlock(): void
    {
        // 512 KB of STDOUT with outputTarget: null and NO manual drain loop.
        // Before draining was moved into join(), this deadlocked forever.
        $bytes = 512 * 1024;
        $thread = new Thread(new FloodTask($bytes));
        $thread->start(outputTarget: null);

        $exit = $thread->join(timeout: 30);

        $this->assertSame(0, $exit, 'bare join() must complete on a child that floods the pipe');
        $this->assertSame($bytes, substr_count($thread->readOutput(), 'A'), 'every byte must survive');
    }

    public function testReadOutputAfterJoinReturnsFullOutput(): void
    {
        // readOutput() after a bare join() now returns the buffered output instead of ''.
        $bytes = 128 * 1024;
        $thread = new Thread(new FloodTask($bytes));
        $thread->start(outputTarget: null);
        $this->assertSame(0, $thread->join(timeout: 30));

        $this->assertSame($bytes, substr_count($thread->readOutput(), 'A'));
    }

    public function testReapPollingDrainsLargeOutput(): void
    {
        // A pool that only ever polls reap() (never join()) must also drain the pipe,
        // or a chatty worker deadlocks on a full buffer and reap() never returns true.
        $bytes = 256 * 1024;
        $thread = new Thread(new FloodTask($bytes));
        $thread->start(outputTarget: null);

        $collected = '';
        $deadline = microtime(true) + 30;
        while (!$thread->reap()) {
            $collected .= $thread->readOutput();
            if (microtime(true) > $deadline) {
                $thread->kill();
                $this->fail('reap() polling did not drain the pipe; the child deadlocked');
            }
            usleep(10_000);
        }
        $collected .= $thread->readOutput();

        $this->assertSame($bytes, substr_count($collected, 'A'));
    }

    public function testBareJoinDrainsLargeStderrWithoutDeadlock(): void
    {
        // STDERR is a separate pipe and can deadlock the same way; join() drains both.
        $bytes = 256 * 1024;
        $thread = new Thread(new FloodTask($bytes, toStderr: true));
        $thread->start(outputTarget: null);

        $this->assertSame(0, $thread->join(timeout: 30));
        $this->assertSame($bytes, substr_count($thread->readError(), 'E'));
    }

    // --- Payload transport (engine-driven) ---

    public function testTempFileEngineStartsAndJoins(): void
    {
        Thread::bindEngine((new AdaptiveEngine(transport: new TempFileTransport())));
        $thread = new Thread(new SleepTask(0));
        $pid = $thread->start();
        $this->assertGreaterThan(0, $pid);
        $this->assertSame(0, $thread->join());
    }

    public function testTempFileEngineDeliversCorrectOutput(): void
    {
        Thread::bindEngine((new AdaptiveEngine(transport: new TempFileTransport())));
        $logFile = sys_get_temp_dir() . '/wt-tmpfile-' . uniqid() . '.log';
        $thread = new Thread(new EchoTask('swoole-safe'));
        $thread->start(outputTarget: $logFile);
        $thread->join();

        $this->assertStringContainsString('swoole-safe', (string) file_get_contents($logFile));
        unlink($logFile);
    }

    public function testShmEngineDeliversCorrectOutput(): void
    {
        if (!extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
        Thread::bindEngine((new AdaptiveEngine(transport: new ShmTransport())));
        $logFile = sys_get_temp_dir() . '/wt-shm-' . uniqid() . '.log';
        $thread = new Thread(new EchoTask('shm-payload'));
        $thread->start(outputTarget: $logFile);
        $thread->join();

        $this->assertStringContainsString('shm-payload', (string) file_get_contents($logFile));
        unlink($logFile);
    }
}
