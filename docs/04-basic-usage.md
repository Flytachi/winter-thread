# 4. Basic Usage

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

### The `Runnable` contract

```php
interface Runnable
{
    public function run(array $args): void;
}
```

- `run()` is the **only** entry point; the whole task lives here.
- Its return value is ignored — signal outcomes through the **exit code**
  (`return`/normal completion → `0`; throwing → non-zero) or through side effects
  (write to a DB, a file, a queue).
- `$args` is an associative array of per-run values (see [Arguments](#arguments)).

### Serializability — the one hard rule

The task object is **serialized in the parent and rebuilt in the child**, so
everything reachable from its properties must survive serialization:

- ✅ Store scalars, arrays, and plain serializable objects (IDs, DTOs, config).
- ✅ Closures and anonymous classes are fine — `opis/closure` handles them.
- ❌ Do **not** store live resources: PDO/mysqli handles, open sockets, stream
  resources, cURL handles, or objects holding them. They cannot cross a process
  boundary.

Open those resources **inside `run()`** instead — the entire point is that the
worker starts with a clean slate and its own fresh connections:

```php
public function run(array $args): void
{
    $pdo = new PDO(...);        // opened here, in the worker — not a property
    // …
}
```

> **Keep the task lean.** The task's *entire* object graph is serialized and
> shipped over the transport on **every** `start()`. A constructor holding a large
> array or a fat DTO means a large payload and a slower spawn. Pass an **identifier**
> and load the heavy data inside `run()`, rather than embedding it in the task.

## 2. Create a `Thread`

```php
use Flytachi\Winter\Thread\Thread;

$thread = new Thread(
    new GenerateReport(42),
    'Reporting',      // namespace  (grouping, shown in the OS process title)
    'ReportBuilder',  // name       (optional; auto-derived from the class if null)
    'job-42'          // tag        (optional instance label)
);
```

Constructing a `Thread` does **not** start anything. The three metadata fields
are cosmetic but invaluable in production — they form the OS **process title**
(visible in `ps`/`htop`):

```
WinterThread Reporting -> ReportBuilder@job-42
```

Details of each field:

- **`namespace`** — a logical grouping (default `''`).
- **`name`** — if you pass `null`, it is auto-derived from the task's class:
  the short class name (e.g. `GenerateReport`), or the literal `anonymous` for an
  anonymous class. Pass a string to override.
- **`tag`** — an optional instance discriminator (default `null`); if omitted, the
  process title shows `@runnable`.

> The process title is only set when `cli_set_process_title()` is available on the
> platform; where it is not, the task still runs — you just don't get the pretty
> `ps` label.

## 3. Start it

`start()` serializes the task, launches the process, and returns its PID. It
**does not block**:

```php
$pid = $thread->start();
echo "started $pid\n";
// main script keeps running immediately
```

### `start()` signature

```php
public function start(
    array   $arguments    = [],
    bool    $debugMode    = false,
    ?string $outputTarget = '/dev/null',
    bool    $detached     = false,
): int
```

- `$arguments` — per-run values (below).
- `$debugMode` — enable child-side error reporting (see [5. Output & Debugging](05-output-and-debugging.md)).
- `$outputTarget` — where stdout/stderr go (see [5. Output & Debugging](05-output-and-debugging.md)).
- `$detached` — daemonize for zombie-free fire-and-forget (see [9. Detached Mode](09-detached-mode.md)).

Returns the launched process **PID** (`int`). Throws
[`ThreadException`](14-api-reference.md#threadexception-class) if the process
fails to start (e.g. `proc_open` denied, bad binary/runner path, or the process
dies immediately).

### One start per `Thread`

A `Thread` guards against being started while it is already running:

```php
$thread->start();
$thread->start(); // ThreadException: "Thread is already running; join()/reap() it
                  // or create a new Thread before starting again."
```

To run the same task again, either `join()`/`reap()` the previous run first, or —
more commonly — create a **new** `Thread`. Reusing a `Thread` after it has
finished and been reaped is allowed; reusing it while alive is not.

### Arguments

Pass per-run values as the first argument to `start()`. They arrive in `run()`'s
`$args`:

```php
$thread->start(['format' => 'csv', 'compress' => true]);

public function run(array $args): void
{
    $format = $args['format'];    // 'csv'  (string)
    $gz     = $args['compress'];  // true   (bool)
}
```

Rules — worth knowing exactly, because they are strict:

- Values must be **scalars or `null`**. Non-scalar, non-null values are silently
  **dropped** (arrays/objects don't cross as arguments — put structured data in
  the task's constructor instead, where it is serialized).
- `true` becomes a **valueless flag** and comes back as boolean `true`.
- `false` and `null` are **skipped entirely** — the key won't appear in `$args`.
  So test presence with `??`/`isset`, not `=== false`.
- Every other scalar is stringified: an `int`/`float`/`bool`-in-a-string arrives
  in `run()` as a **string** (`'42'`, not `42`). Cast as needed.
- Keys are stringified too.

Internally they travel as escaped `--arg-<key>[=<value>]` CLI options and are
parsed back for you — you never touch the command line, and values cannot inject
into the shell.

> **Arguments vs. constructor.** Use the **constructor** for the task's real
> payload (it is serialized, keeps types, and accepts any serializable value). Use
> **`$arguments`** for small per-run scalar switches (`format`, `verbose`). If you
> find yourself flattening structures into arguments, move them to the
> constructor.

## 4. Wait for the result (optional)

`join()` blocks until the task finishes and returns its exit code — `0` on
success, non-zero on failure:

```php
$exit = $thread->join();
if ($exit !== 0) {
    // the task threw or failed
}
```

- `join(int $timeout = 0)` — waits up to `$timeout` **seconds** (`0` = forever).
  Returns `null` on timeout, `-1` if the thread was never started. Internally it
  polls process status every 50 ms.
- If you never call `join()`/`reap()`, see [6. Process Control](06-process-control.md)
  for how the engine still avoids leaving zombie processes behind.

## Fire-and-forget

Don't want a result? Just start and move on. Output defaults to `/dev/null`, so
this is safe:

```php
new Thread(new SendWelcomeEmail($userId))->start();
```

For a **long-lived** parent (FPM worker, daemon) that never joins, use
[detached mode](09-detached-mode.md) so no zombie accumulates:

```php
new Thread(new SendWelcomeEmail($userId))->start(detached: true);
```

## Exit codes & failures

| Outcome | Exit code | Where to look |
|---|---|---|
| `run()` returns normally | `0` | — |
| `run()` throws an uncaught exception | non-zero (`1`) | message + stack trace on STDERR |
| Payload empty / not a `Runnable` | `1` | STDERR |
| Payload can't be deserialized (tampered, or signed with the wrong secret) | `1` | STDERR; **no task code runs** |

The runner **always** catches exceptions from `run()` — it logs the message and
trace to STDERR and exits non-zero — so failures are detectable via `join()` even
without [debug mode](05-output-and-debugging.md). Where STDERR goes depends on
your `$outputTarget`.

> **Avoid `exit()`/`die()` inside `run()`.** They bypass the runner's normal path:
> the exit code becomes whatever you passed to `exit()` (so `exit(0)` on a failure
> would look like success), and the exception handling above is skipped. Return
> normally for success, and `throw` for failure — let the runner set the code.
