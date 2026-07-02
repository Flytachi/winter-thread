# Winter Thread — Documentation

Object-oriented background-process control for PHP — a Java-like threading model
over OS processes, **without heavy extensions** (no swoole / parallel / pthreads,
no ZTS build). Just `proc_open` and standard POSIX.

## Mental model in 30 seconds

You implement a `Runnable`, wrap it in a `Thread`, and `start()` it. The library
serializes the task, spawns a **fresh PHP CLI process** (`proc_open` → the packaged
`wRunner` bootstrap), and runs your task there — fully isolated from the parent.
You then `join()` for the exit code, `reap()` non-blocking in a loop, or fire and
forget. Everything configurable lives behind a single pluggable `Engine`.

## Table of contents

1. [Introduction](01-introduction.md) — what it is, the no-heavy-ext philosophy, when (not) to use it
2. [Installation & Requirements](02-installation-and-requirements.md) — deps, FPM, containers, the `wRunner` path
3. [Quickstart](03-quickstart.md) — a complete run-in-parallel-and-collect example
4. [Basic Usage](04-basic-usage.md) — `Runnable`, `Thread`, `start()`/`join()`, arguments, exit codes
5. [Output & Debugging](05-output-and-debugging.md) — output targets, the Broken-pipe trap, debug mode
6. [Process Control & Lifecycle](06-process-control.md) — signals, graceful shutdown, `reap()`, `detach()`
7. [The Engine](07-the-engine.md) — `AdaptiveEngine` / `ManualEngine`, parent-vs-child, custom launchers
8. [Payload Transports](08-payload-transports.md) — pipe / temp-file / shm, Swoole compatibility
9. [Detached Mode](09-detached-mode.md) — zombie-free fire-and-forget, container init
10. [Security](10-security.md) — signed payloads, the trust model, object-injection defense
11. [Architecture & Internals](11-architecture.md) — components, the two-process model, building a pool
12. [Patterns](12-patterns.md) — bounded pool, returning results, nested threads, retries
13. [Troubleshooting](13-troubleshooting.md) — symptom → cause → fix
14. [API Reference](14-api-reference.md) — every public class and method, parameters & returns
15. [Testing](15-testing.md) — the two-tier suite & CI

## Quick start

```php
use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

class MyTask implements Runnable {
    public function run(array $args): void { /* heavy work in a clean process */ }
}

$thread = new Thread(new MyTask());
$thread->start();   // fire-and-forget (output → /dev/null)
$thread->join();    // or wait for the exit code
```

## Key facts to internalize

- **One task = one fresh PHP process.** Great for work larger than the ~few-ms
  spawn cost; wrong for millions of microtasks. ([1](01-introduction.md))
- **`/dev/null` is the default output** — piping to an unread parent (`null`)
  risks a Broken-pipe stall. ([5](05-output-and-debugging.md))
- **`reap()`/`detach()` never block on a live worker** — the basis for a pool
  loop. ([6](06-process-control.md), [12](12-patterns.md))
- **`Engine` is parent-side only; the child runs a separate `AdaptiveRunner`.**
  The signing secret reaches the child via the `WINTER_THREAD_SECRET` env var.
  ([7](07-the-engine.md), [10](10-security.md))
- **`detach()` ≠ detached mode.** Only `start(detached: true)` (fork + setsid)
  is zombie-free under a long-lived parent. ([9](09-detached-mode.md))

New here? Start with the [Introduction](01-introduction.md).
