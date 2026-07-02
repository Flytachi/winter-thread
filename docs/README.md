# Winter Thread — Documentation

Object-oriented background-process control for PHP — a Java-like threading model
over OS processes, **without heavy extensions** (no swoole / parallel / pthreads,
no ZTS build). Just `proc_open` and standard POSIX.

## Table of contents

1. [Introduction](01-introduction.md) — what it is, the no-heavy-ext philosophy, when to use it
2. [Installation & Requirements](02-installation-and-requirements.md)
3. [Basic Usage](03-basic-usage.md) — `Runnable`, `Thread`, `start()`/`join()`, arguments
4. [Output & Debugging](04-output-and-debugging.md) — output targets, debug mode
5. [Process Control & Lifecycle](05-process-control.md) — signals, `reap()`, `detach()`
6. [The Engine](06-the-engine.md) — `AdaptiveEngine` / `ManualEngine`, configuration
7. [Payload Transports](07-payload-transports.md) — pipe / temp-file / shm, Swoole compatibility
8. [Detached Mode](08-detached-mode.md) — zombie-free fire-and-forget
9. [Security](09-security.md) — signed payloads, object-injection defense
10. [Architecture & Internals](10-architecture.md) — components, building a pool
11. [API Reference](11-api-reference.md) — every public class and method
12. [Testing](12-testing.md) — the two-tier test suite & CI

## Quick start

```php
use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

class MyTask implements Runnable {
    public function run(array $args): void { /* heavy work in a clean process */ }
}

$thread = new Thread(new MyTask());
$thread->start();   // fire-and-forget
$thread->join();    // or wait for the exit code
```

New here? Start with the [Introduction](01-introduction.md).
