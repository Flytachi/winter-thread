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
