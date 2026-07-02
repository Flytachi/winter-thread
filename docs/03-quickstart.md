# 3. Quickstart

A complete, runnable example in five minutes. We'll run **three jobs in parallel**,
each producing a result file, then collect their results in the parent. By the end
you'll have used the whole core loop: *define → start → wait → collect*.

> Prerequisite: the package is installed (see
> [2. Installation](02-installation-and-requirements.md)). Everything below is one
> self-contained CLI script.

## Step 1 — Define the task

A task implements [`Runnable`](14-api-reference.md#runnable-interface); its logic
lives in `run()`, which executes in a **separate, clean PHP process**. Because
workers don't share memory with the parent, we return the result by **writing a
file** (a database row or queue message works the same way):

```php
use Flytachi\Winter\Thread\Runnable;

final class ComputeStats implements Runnable
{
    /** @param int[] $numbers */
    public function __construct(
        private array $numbers,
        private string $outDir,
    ) {}

    public function run(array $args): void
    {
        $label = $args['label'] ?? 'batch';       // a per-run argument (a string)
        $sum   = array_sum($this->numbers);
        $avg   = $sum / max(1, count($this->numbers));

        // "Return" the result by writing it where the parent can read it.
        file_put_contents(
            "{$this->outDir}/{$label}.json",
            json_encode(['label' => $label, 'sum' => $sum, 'avg' => $avg]),
        );
    }
}
```

## Step 2 — Start jobs in parallel

Wrap each task in a [`Thread`](14-api-reference.md#thread-final-class) and
`start()` it. `start()` returns **immediately** with the worker's PID — all three
run at the same time:

```php
use Flytachi\Winter\Thread\Thread;

$dir = sys_get_temp_dir() . '/wt-quickstart';
@mkdir($dir);

$batches = [
    'evens' => [2, 4, 6, 8],
    'odds'  => [1, 3, 5, 7],
    'primes'=> [2, 3, 5, 7, 11],
];

/** @var Thread[] $threads */
$threads = [];
foreach ($batches as $label => $numbers) {
    $t = new Thread(new ComputeStats($numbers, $dir), 'Stats', 'ComputeStats', $label);
    $t->start(['label' => $label]);   // fire — non-blocking
    $threads[$label] = $t;
    echo "started {$label} (pid {$t->getPid()})\n";
}
```

## Step 3 — Wait and collect

`join()` blocks until a worker finishes and returns its **exit code** (`0` =
success). Then read the result each worker wrote:

```php
foreach ($threads as $label => $t) {
    $exit = $t->join();                       // wait for this worker
    if ($exit !== 0) {
        echo "{$label}: FAILED (exit {$exit})\n";
        continue;
    }
    $result = json_decode(file_get_contents("{$dir}/{$label}.json"), true);
    echo "{$label}: sum={$result['sum']} avg={$result['avg']}\n";
}
```

## Step 4 — Run it

```bash
php quickstart.php
```

Expected output (PIDs vary; the three run concurrently):

```
started evens (pid 51234)
started odds (pid 51235)
started primes (pid 51236)
evens: sum=20 avg=5
odds: sum=16 avg=4
primes: sum=28 avg=5.6
```

Three result files now exist under the temp dir — visible proof each task ran in
its own process.

## The whole script

```php
<?php
require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

final class ComputeStats implements Runnable
{
    /** @param int[] $numbers */
    public function __construct(private array $numbers, private string $outDir) {}

    public function run(array $args): void
    {
        $label = $args['label'] ?? 'batch';
        $sum   = array_sum($this->numbers);
        $avg   = $sum / max(1, count($this->numbers));
        file_put_contents(
            "{$this->outDir}/{$label}.json",
            json_encode(['label' => $label, 'sum' => $sum, 'avg' => $avg]),
        );
    }
}

$dir = sys_get_temp_dir() . '/wt-quickstart';
@mkdir($dir);

$batches = ['evens' => [2, 4, 6, 8], 'odds' => [1, 3, 5, 7], 'primes' => [2, 3, 5, 7, 11]];

$threads = [];
foreach ($batches as $label => $numbers) {
    $t = new Thread(new ComputeStats($numbers, $dir), 'Stats', 'ComputeStats', $label);
    $t->start(['label' => $label]);
    $threads[$label] = $t;
    echo "started {$label} (pid {$t->getPid()})\n";
}

foreach ($threads as $label => $t) {
    $exit = $t->join();
    if ($exit !== 0) { echo "{$label}: FAILED (exit {$exit})\n"; continue; }
    $r = json_decode(file_get_contents("{$dir}/{$label}.json"), true);
    echo "{$label}: sum={$r['sum']} avg={$r['avg']}\n";
}
```

## What you just used

- **`Runnable`** — the task contract; logic in `run()`, executed in an isolated
  process. ([4. Basic Usage](04-basic-usage.md))
- **`Thread::start()`** — non-blocking launch, returns a PID. ([4](04-basic-usage.md))
- **Arguments** — the `['label' => …]` map arrives in `run()`'s `$args` (as
  strings/flags). ([4](04-basic-usage.md#arguments))
- **`join()`** — wait for the exit code. ([6. Process Control](06-process-control.md))
- **Returning results via a file** — the isolation-friendly pattern. ([12. Patterns](12-patterns.md#returning-a-result-from-a-task))

## Next steps

- Fire-and-forget without waiting, and output handling →
  [4. Basic Usage](04-basic-usage.md), [5. Output & Debugging](05-output-and-debugging.md).
- Run **many** jobs with bounded concurrency (a pool) →
  [12. Patterns](12-patterns.md#a-bounded-worker-pool).
- Long-lived parent (FPM/daemon) that never waits →
  [9. Detached Mode](09-detached-mode.md).
- Something not working? → [13. Troubleshooting](13-troubleshooting.md).
