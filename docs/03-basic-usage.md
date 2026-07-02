# 3. Basic Usage

The core workflow: **define a task**, **wrap it in a `Thread`**, **start it**,
and optionally **wait** for the result.

## 1. Define a task with `Runnable`

Any class implementing [`Runnable`](../src/Runnable.php) can run in a background
process. All logic goes in `run()`:

```php
use Flytachi\Winter\Thread\Runnable;

class GenerateReport implements Runnable
{
    public function __construct(private int $reportId) {}

    public function run(array $args): void
    {
        // Executes in a separate, clean PHP process.
        $format = $args['format'] ?? 'pdf';
        // … heavy work …
    }
}
```

The object must be **serializable**: don't store live resources (PDO handles,
open sockets, stream resources) in its properties. Open them *inside* `run()`
instead — the whole point is that the worker starts with a clean slate.

`run()` receives an `$args` array of per-run values (see [Arguments](#arguments)).

## 2. Create a `Thread`

```php
use Flytachi\Winter\Thread\Thread;

$thread = new Thread(
    new GenerateReport(42),
    'Reporting',      // namespace  (grouping, shown in the OS process title)
    'ReportBuilder',  // name       (auto-derived from the class if null)
    'job-42'          // tag        (optional instance label)
);
```

The three metadata fields are cosmetic but invaluable in production: they form
the process title (visible in `ps`/`htop`), e.g.
`WinterThread Reporting -> ReportBuilder@job-42`.

## 3. Start it

`start()` serializes the task, launches the process, and returns its PID. It
**does not block**:

```php
$pid = $thread->start();
echo "started $pid\n";
// main script keeps running immediately
```

### Arguments

Pass per-run values as the first argument to `start()`. They arrive in `run()`'s
`$args`:

```php
$thread->start(['format' => 'csv', 'compress' => true]);

public function run(array $args): void
{
    $format = $args['format'];    // 'csv'
    $gz     = $args['compress'];  // true
}
```

Values must be scalars or `null`. `true` becomes a valueless flag; `false` and
`null` are skipped. (Internally they are passed as `--arg-*` CLI options and
parsed back for you — you never deal with the CLI.)

### `start()` signature

```php
public function start(
    array   $arguments   = [],
    bool    $debugMode   = false,
    ?string $outputTarget = '/dev/null',
    bool    $detached    = false,
): int
```

- `$arguments` — per-run values (above).
- `$debugMode` — enable child-side error reporting (see [4. Output & Debugging](04-output-and-debugging.md)).
- `$outputTarget` — where stdout/stderr go (see [4. Output & Debugging](04-output-and-debugging.md)).
- `$detached` — daemonize for zombie-free fire-and-forget (see [8. Detached Mode](08-detached-mode.md)).

## 4. Wait for the result (optional)

`join()` blocks until the task finishes and returns its exit code — `0` on
success, non-zero on failure:

```php
$exit = $thread->join();
if ($exit !== 0) {
    // the task threw or failed
}
```

- `join(int $timeout = 0)` — waits up to `$timeout` seconds (`0` = forever).
  Returns `null` on timeout, `-1` if the thread was never started.
- If you never call `join()`/`reap()`, see [5. Process Control](05-process-control.md)
  for how the engine avoids leaving zombie processes behind.

## Fire-and-forget

Don't want a result? Just start and move on. Output defaults to `/dev/null`, so
this is safe:

```php
new Thread(new SendWelcomeEmail($userId))->start();
```

For a long-lived parent (FPM worker, daemon) that never joins, use detached mode
so no zombie accumulates — see [8. Detached Mode](08-detached-mode.md).

## Exit codes & failures

- The task returns normally → exit code `0`.
- The task throws an uncaught exception → the runner logs it to STDERR and exits
  non-zero.
- The payload can't be deserialized (e.g. tampered, or signed with the wrong
  secret) → exit non-zero, no code runs.
