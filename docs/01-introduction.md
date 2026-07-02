# 1. Introduction

**Winter Thread** is a process engine for PHP: an object-oriented, Java-like API
for running and controlling background tasks as isolated OS processes.

It is deliberately a *low-level engine* — a small, dependable core you build
higher-level concurrency on (pools, queues, schedulers, workers), rather than a
batteries-included framework.

## The core idea

A task is any object implementing [`Runnable`](../src/Runnable.php). You wrap it
in a `Thread` and start it. Under the hood Winter Thread serializes the task,
spawns a **fresh PHP CLI process** via `proc_open`, and runs the task there —
completely isolated from the parent:

```php
class SendReport implements Runnable {
    public function __construct(private int $userId) {}
    public function run(array $args): void {
        // Runs in its own clean PHP process.
    }
}

$thread = new Thread(new SendReport(42));
$pid = $thread->start();   // returns immediately
$thread->join();           // optionally wait for the result
```

## No heavy extensions

This is the defining trait of the engine. Real parallelism in PHP usually means
one of the heavyweight options:

| Approach | Needs |
|---|---|
| `pthreads` | a ZTS PHP build + PECL extension (abandoned on modern PHP) |
| `ext-parallel` | a ZTS build + PECL extension |
| Swoole / OpenSwoole | a large C extension + an event-loop programming model |

**Winter Thread needs none of them.** It runs on a *standard* PHP build using:

- **`proc_open`** — a core function, always available (unless explicitly disabled);
- **`ext-pcntl` + `ext-posix`** — lightweight, standard POSIX extensions that ship
  with virtually every Linux/macOS PHP CLI, used for signals and the optional
  detached mode;
- **`opis/closure`** — a pure-PHP Composer package for safe serialization.

`ext-shmop` is *optional* and only needed for the shared-memory transport. There
is no ZTS requirement, no event loop, no exotic runtime. If your PHP can call
`proc_open`, the engine works.

## Why processes instead of threads

PHP has no safe shared-memory threads. Winter Thread embraces that and spawns
**processes**, which fits how modern PHP applications are actually built:

- **Clean isolation.** Each task starts in a brand-new PHP process — no inherited
  database connections, Redis sockets, DI container, or global state. There is
  nothing to corrupt and nothing to leak between tasks.
- **No `fork()` foot-guns.** Approaches based on `pcntl_fork()` duplicate the
  parent — including open PDO/Redis file descriptors, which break in subtle ways
  when a child closes them. A fresh `proc_open` process simply doesn't have them.
- **Runs where you run.** Because it is just `proc_open`, tasks can be launched
  from a CLI worker, a daemon, or even inside a PHP-FPM web request (where
  `proc_open` is permitted).

## What you get

- A fluent, **Java-like API**: `start()`, `join()`, `isAlive()`, `pause()`,
  `resume()`, `terminate()`, `kill()`, plus `reap()`, `detach()` and
  `getExitCode()`.
- **Pluggable payload transports** (pipe / temp-file / shared-memory) with
  automatic selection under Swoole.
- **Zombie-free fire-and-forget** via an optional detached mode.
- **Signed serialization** to defend against payload tampering.
- A **pluggable `Engine`** so you can swap transport, launcher, or the child-side
  runner — and build custom backends (Docker, SSH, …) — without touching `Thread`.

## When to use it

**Great for:**

- heavy background jobs that must not block the request/worker (reports, exports,
  parsing, media processing, batch e-mail);
- long-running or blocking work you want isolated from the main process;
- a foundation for your own pool / scheduler / worker primitives.

**Not ideal for:**

- huge numbers of *tiny* tasks. Each task is a full PHP process: spawning one
  costs a few milliseconds of interpreter start-up. For thousands of
  microsecond-sized operations that overhead dominates. Use it for work that is
  meaningfully larger than the spawn cost.

## Where to next

- [2. Installation & Requirements](02-installation-and-requirements.md)
- [3. Basic Usage](03-basic-usage.md)
- [4. Output & Debugging](04-output-and-debugging.md)
- [5. Process Control & Lifecycle](05-process-control.md)
- [6. The Engine](06-the-engine.md)
- [7. Payload Transports](07-payload-transports.md)
- [8. Detached Mode](08-detached-mode.md)
- [9. Security](09-security.md)
- [10. Architecture & Internals](10-architecture.md)
- [11. API Reference](11-api-reference.md)
- [12. Testing](12-testing.md)
