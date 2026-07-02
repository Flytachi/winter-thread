# 12. Patterns

Practical recipes for the common real-world needs, built on the primitives from
the earlier chapters. Each is copy-paste ready; the facts they rely on link back to
the [API reference](14-api-reference.md).

## A bounded worker pool

Running a handful of jobs is just "start them all, then `join()` each" (see
[Quickstart](03-quickstart.md)). But firing **hundreds** at once would spawn
hundreds of PHP processes and exhaust RAM/PIDs. The fix is a **bounded pool**: keep
at most *N* workers alive, and launch the next job only as a slot frees.

The engine is designed for exactly this: [`reap()`](14-api-reference.md#thread-final-class)
is **non-blocking on a live worker**, so one loop can fill slots and harvest
finished workers without ever stalling.

```php
use Flytachi\Winter\Thread\Thread;

/**
 * @param iterable<Runnable> $jobs        tasks to run
 * @param int                $concurrency max workers alive at once
 * @param callable(Thread):void $onDone   called when a worker finishes
 */
function runPool(iterable $jobs, int $concurrency, callable $onDone): void
{
    $queue   = is_array($jobs) ? $jobs : iterator_to_array($jobs);
    $running = [];

    while ($queue !== [] || $running !== []) {
        // 1) Fill free slots up to the cap.
        while (count($running) < $concurrency && $queue !== []) {
            $task = array_shift($queue);
            $t = new Thread($task);
            $t->start();                     // non-blocking
            $running[] = $t;
        }

        // 2) Harvest finished workers (never blocks on a live one).
        foreach ($running as $i => $t) {
            if ($t->reap()) {                // true → finished and collected
                $onDone($t);                 // inspect $t->getExitCode()
                unset($running[$i]);
            }
        }
        $running = array_values($running);

        usleep(20_000);                      // 20 ms between passes
    }
}
```

Usage:

```php
$jobs = array_map(fn($id) => new ProcessOrder($id), $orderIds);

runPool($jobs, concurrency: 10, onDone: function (Thread $t) {
    $code = $t->getExitCode();
    echo $t->getTag() ?? $t->getName(), $code === 0 ? " ok\n" : " FAILED ($code)\n";
});
```

Notes:

- **Pick `concurrency` from your resources**, not the job count — often around the
  CPU-core count for CPU-bound work, higher for I/O-bound work. Each worker is a
  full PHP process.
- The 20 ms sleep keeps the harvest loop from busy-spinning; tune it to your job
  durations.
- Framework authors building a reusable pool can drop the `Thread` facade and drive
  [`Launcher`](14-api-reference.md#launcher-interface) +
  [`ProcessHandle`](14-api-reference.md#processhandle-final-class) directly — same
  loop shape. See [11. Architecture](11-architecture.md#building-a-pool-on-launcher--processhandle).

## Returning a result from a task

Workers are **isolated processes** — they share no memory with the parent, so you
can't just `return` a value or set a property. Hand the result back through a
channel both sides can reach. Three common choices:

| Channel | Good for | Notes |
|---|---|---|
| **A file** | anything, simplest | write in `run()`, read after `join()`; use a unique path |
| **A database row / cache key** | structured or queryable results | the worker opens its own connection inside `run()` |
| **A queue / stream** | streaming or many consumers | e.g. Redis list, message broker |

The file pattern, end to end:

```php
final class RenderThumbnail implements Runnable
{
    public function __construct(private string $src, private string $resultPath) {}

    public function run(array $args): void
    {
        $bytes = /* … do the work … */ 12345;
        file_put_contents($this->resultPath, json_encode(['src' => $this->src, 'bytes' => $bytes]));
    }
}

$resultPath = tempnam(sys_get_temp_dir(), 'thumb_');
$t = new Thread(new RenderThumbnail('/img/a.png', $resultPath));
$t->start();

if ($t->join() === 0) {
    $result = json_decode(file_get_contents($resultPath), true);
    // … use $result …
}
@unlink($resultPath);                 // clean up when you're done
```

Rules of thumb:

- **Use a unique result path per job** (`tempnam()`), so parallel workers never
  collide.
- **Check the exit code first.** A non-zero exit means `run()` failed — the result
  file may be missing or partial; don't trust it.
- **Don't put resources in task properties.** Open the DB/cache connection *inside*
  `run()`; only serializable values (the `$resultPath`, IDs) belong in the
  constructor. See [4. Basic Usage](04-basic-usage.md#serializability--the-one-hard-rule).

## Fire-and-forget under a long-lived parent

An FPM worker or daemon that dispatches jobs and never waits must not accumulate
zombies. Use [detached mode](09-detached-mode.md) and log to a file (there's no
parent to drain pipes):

```php
$thread = new Thread(new SendEmailBatch($ids));
$thread->start(detached: true, outputTarget: '/var/log/app/mail.log');
// returns at once; the worker is reparented to init and reaped there
```

In a container where your app is PID 1, add a reaping init (`docker run --init` /
`init: true`) so detached workers are collected. See
[9. Detached Mode](09-detached-mode.md#containers-give-pid-1-a-reaper).

## Nested threads

A task may itself spawn threads — a `Thread` inside a `run()` works to arbitrary
depth (the test suite exercises three levels). A coordinator job can fan out
sub-jobs and `join()` them:

```php
final class BuildReport implements Runnable
{
    public function run(array $args): void
    {
        $parts = array_map(fn($s) => new BuildSection($s), ['sales', 'costs', 'trends']);
        $threads = array_map(function ($p) { $t = new Thread($p); $t->start(); return $t; }, $parts);
        foreach ($threads as $t) { $t->join(); }
        // … stitch the sections together …
    }
}
```

Caveat: each nesting level is another PHP process, so total concurrency multiplies
— bound it (a pool at each level) if the fan-out is wide. If the *inner* threads
are detached, the same [container-init](09-detached-mode.md#containers-give-pid-1-a-reaper)
rule applies to whichever process is their PID 1.

## Retry on failure

The exit code makes retries trivial — re-run until success or a cap:

```php
function runWithRetry(callable $makeTask, int $attempts = 3): bool
{
    for ($i = 1; $i <= $attempts; $i++) {
        $t = new Thread($makeTask());
        $t->start();
        if ($t->join() === 0) {
            return true;
        }
        usleep(100_000 * $i);          // simple backoff
    }
    return false;
}

$ok = runWithRetry(fn() => new ChargeCard($invoiceId));
```

Make the task **idempotent** (safe to run twice) before retrying side-effecting
work, since a failure might occur after the effect but before exit.
