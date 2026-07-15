<?php

declare(strict_types=1);

namespace Flytachi\Winter\Thread\Tests\Container\Timing;

use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Payload\PipeTransport;
use Flytachi\Winter\Thread\Payload\ShmTransport;
use Flytachi\Winter\Thread\Payload\TempFileTransport;
use Flytachi\Winter\Thread\Tests\Fixtures\SleepTask;
use Flytachi\Winter\Thread\Thread;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Startup-overhead regression detector. Thresholds are deliberately generous —
 * the goal is to catch catastrophic regressions (e.g. an accidental blocking
 * wait), not to benchmark. Medians over several runs smooth out scheduler noise.
 */
#[Group('container')]
#[Group('timing')]
class OverheadTest extends TestCase
{
    private const ITERATIONS = 15;

    /** Loose ceiling for a full PHP process spawn + bootstrap round-trip. */
    private const ATTACHED_CEILING_SECONDS = 3.0;

    protected function tearDown(): void
    {
        Thread::bindLauncher(CliLauncher::adaptive());
    }

    /** @return array<string, array{class-string, bool}> */
    public static function transportProvider(): array
    {
        return [
            'pipe'     => [PipeTransport::class, false],
            'tempfile' => [TempFileTransport::class, false],
            'shm'      => [ShmTransport::class, true],
        ];
    }

    /** @param array<int, float> $samples */
    private function median(array $samples): float
    {
        sort($samples);
        $n = count($samples);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $samples[$mid] : ($samples[$mid - 1] + $samples[$mid]) / 2;
    }

    #[DataProvider('transportProvider')]
    public function testAttachedRoundTripLatency(string $transportClass, bool $needsShmop): void
    {
        if ($needsShmop && !extension_loaded('shmop')) {
            $this->markTestSkipped('ext-shmop not available.');
        }
        Thread::bindLauncher(CliLauncher::adaptive(transport: new $transportClass()));

        $samples = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $t0 = microtime(true);
            $thread = new Thread(new SleepTask(0));
            $thread->start();
            $thread->join();
            $samples[] = microtime(true) - $t0;
        }

        $median = $this->median($samples);
        $this->assertLessThan(
            self::ATTACHED_CEILING_SECONDS,
            $median,
            sprintf('%s attached median %.3fs exceeds ceiling', $transportClass, $median),
        );
    }

    public function testDetachedStartDoesNotBlockOnTheTask(): void
    {
        if (!function_exists('posix_setsid')) {
            $this->markTestSkipped('ext-posix not available.');
        }
        Thread::bindLauncher(CliLauncher::adaptive());

        // The worker sleeps 2s; a correct detached start returns in ~spawn time
        // (well under 1.5s). A blocking start would land around 2s+ and fail.
        $samples = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $t0 = microtime(true);
            $thread = new Thread(new SleepTask(2));
            $thread->start(detached: true);
            $samples[] = microtime(true) - $t0;
            $thread->join(); // reap the ephemeral launcher only
            unset($thread);
        }

        $median = $this->median($samples);
        $this->assertLessThan(
            1.5,
            $median,
            sprintf('detached start median %.3fs — start appears to block on the task', $median),
        );
    }
}
