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
3. [Basic Usage](03-basic-usage.md) — `Runnable`, `Thread`, `start()`/`join()`, arguments, exit codes
4. [Output & Debugging](04-output-and-debugging.md) — output targets, the Broken-pipe trap, debug mode
5. [Process Control & Lifecycle](05-process-control.md) — signals, `reap()`, `detach()`, the non-blocking guarantee
6. [The Engine](06-the-engine.md) — `AdaptiveEngine` / `ManualEngine`, parent-vs-child, custom launchers
7. [Payload Transports](07-payload-transports.md) — pipe / temp-file / shm, Swoole compatibility
8. [Detached Mode](08-detached-mode.md) — zombie-free fire-and-forget, container init
9. [Security](09-security.md) — signed payloads, the trust model, object-injection defense
10. [Architecture & Internals](10-architecture.md) — components, the two-process model, building a pool
11. [API Reference](11-api-reference.md) — every public class and method, exact signatures
12. [Testing](12-testing.md) — the two-tier suite & CI

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
  risks a Broken-pipe stall. ([4](04-output-and-debugging.md))
- **`reap()`/`detach()` never block on a live worker** — the basis for a pool
  loop. ([5](05-process-control.md))
- **The child always rebuilds its own `AdaptiveEngine`**; the signing secret
  reaches it via the `WINTER_THREAD_SECRET` env var. ([6](06-the-engine.md),
  [9](09-security.md))
- **`detach()` ≠ detached mode.** Only `start(detached: true)` (fork + setsid)
  is zombie-free under a long-lived parent. ([8](08-detached-mode.md))

New here? Start with the [Introduction](01-introduction.md).
