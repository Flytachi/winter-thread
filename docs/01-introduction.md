# 1. Introduction

**Winter Thread** is a *process engine* for PHP: an object-oriented, Java-like API
for running and controlling background tasks as isolated OS processes.

It is deliberately a **low-level engine** — a small, dependable core you build
higher-level concurrency on (pools, queues, schedulers, workers), rather than a
batteries-included framework. It gives you clean primitives and stays out of your
way.

## The core idea

A task is any object implementing [`Runnable`](../src/Runnable.php). You wrap it
in a `Thread` and start it. Under the hood Winter Thread serializes the task,
spawns a **fresh PHP CLI process**, and runs the task there — completely isolated
from the parent:

```php
class SendReport implements Runnable {
    public function __construct(private int $userId) {}
    public function run(array $args): void {
        // Runs in its own clean PHP process.
    }
}

$thread = new Thread(new SendReport(42));
$pid = $thread->start();   // returns immediately, gives you the worker PID
$thread->join();           // optionally wait for the exit code
```

### What actually happens on `start()`

1. The `Runnable` is serialized with [`opis/closure`](10-security.md) (optionally
   HMAC-signed).
2. A [payload transport](08-payload-transports.md) *stages* those bytes for
   delivery (stdin pipe by default).
3. The engine builds a shell-escaped command like
   `php <path>/wRunner --namespace=… --name=…` and runs it through `proc_open`.
4. `proc_open` returns immediately with a live process; you get its PID.
5. The child bootstrap script `wRunner` reads the payload back, verifies and
   deserializes it into your `Runnable`, and calls `run()`.

Everything after step 4 happens **concurrently** with your main script. That is
the whole point.

## No heavy extensions

This is the defining trait of the engine. Real parallelism in PHP usually means
one of the heavyweight options:

| Approach | Needs |
|---|---|
| `pthreads` | a ZTS PHP build + PECL extension (abandoned on modern PHP) |
| `ext-parallel` | a ZTS build + PECL extension |
| Swoole / OpenSwoole | a large C extension + an event-loop programming model |

**Winter Thread needs none of them.** It runs on a *standard* PHP build using:

- **`proc_open`** — a core function, always available (unless explicitly disabled
  via `disable_functions`);
- **`ext-pcntl` + `ext-posix`** — lightweight, standard POSIX extensions that ship
  with virtually every Linux/macOS PHP CLI, used for signals and the optional
  detached mode;
- **`opis/closure`** — a pure-PHP Composer package for safe serialization.

`ext-shmop` is *optional* and only needed for the [shared-memory
transport](08-payload-transports.md). There is no ZTS requirement, no event loop,
no exotic runtime. **If your PHP can call `proc_open`, the engine works** — and it
[coexists with Swoole](08-payload-transports.md#swoole--event-loop-compatibility)
when you do run one.

## Why processes instead of threads

PHP has no safe shared-memory threads. Winter Thread embraces that and spawns
**processes**, which fits how modern PHP applications are actually built:

- **Clean isolation.** Each task starts in a brand-new PHP process — no inherited
  database connections, Redis sockets, DI container, opcode state, or globals.
  There is nothing to corrupt and nothing to leak between tasks.
- **No `fork()` foot-guns.** Approaches based on `pcntl_fork()` of your *main*
  process duplicate it — including open PDO/Redis file descriptors, which break in
  subtle ways when a child closes them. A fresh `proc_open` process simply doesn't
  have them. (Detached mode *does* use one `fork`, but inside the already-clean
  worker — never your app; see [9. Detached Mode](09-detached-mode.md).)
- **Runs where you run.** Because it is just `proc_open`, tasks can be launched
  from a CLI worker, a daemon, or even inside a PHP-FPM web request (where
  `proc_open` is permitted).

## What you get

- A fluent, **Java-like API**: `start()`, `join()`, `isAlive()`, `pause()`,
  `resume()`, `interrupt()`, `terminate()`, `kill()`, plus `reap()`, `detach()`
  and `getExitCode()`.
- **Pluggable payload transports** (pipe / temp-file / shared-memory) with
  automatic selection under Swoole.
- **Zombie-free fire-and-forget** via an optional [detached mode](09-detached-mode.md).
- **Signed serialization** to defend against payload tampering / object injection.
- A **pluggable [`Engine`](07-the-engine.md)** so you can swap the payload transport
  or the launcher — and build custom backends (Docker, SSH, …) — without touching
  `Thread`.
- A **non-blocking control model** (`reap()`/`detach()` never stall on a live
  worker), so a single loop can drive hundreds of workers — the foundation for a
  pool.

## When to use it

**Great for:**

- heavy background jobs that must not block the request/worker (reports, exports,
  parsing, media processing, batch e-mail);
- long-running or blocking work you want isolated from the main process;
- a foundation for your own pool / scheduler / worker primitives.

**Not ideal for:**

- **Huge numbers of *tiny* tasks.** Each task is a full PHP process; spawning one
  costs a few milliseconds of interpreter start-up plus the serialize/deserialize
  round-trip. For thousands of microsecond-sized operations that overhead
  dominates — use it for work meaningfully larger than the spawn cost, or amortize
  the cost with a long-lived worker that pulls many jobs.
- **Shared mutable state between tasks.** Isolation is a feature, not a limitation
  to work around: workers don't share memory. Coordinate through a database, a
  queue, or files — not in-process variables.
- **Windows.** The engine targets POSIX (signals, `setsid`, `/proc`); it is
  developed and tested on Linux and macOS.

## Where to next

- [2. Installation & Requirements](02-installation-and-requirements.md)
- [3. Quickstart](03-quickstart.md) — a complete parallel example in 5 minutes
- [4. Basic Usage](04-basic-usage.md)
- [5. Output & Debugging](05-output-and-debugging.md)
- [6. Process Control & Lifecycle](06-process-control.md)
- [7. The Engine](07-the-engine.md)
- [8. Payload Transports](08-payload-transports.md)
- [9. Detached Mode](09-detached-mode.md)
- [10. Security](10-security.md)
- [11. Architecture & Internals](11-architecture.md)
- [12. Patterns](12-patterns.md) — pools, returning results, retries
- [13. Troubleshooting](13-troubleshooting.md)
- [14. API Reference](14-api-reference.md)
- [15. Testing](15-testing.md)
