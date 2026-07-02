# 2. Installation & Requirements

## Install

```bash
composer require flytachi/winter-thread
```

Installing the package also exposes the child bootstrap script as
`vendor/bin/wRunner`. You never call it by hand — the engine invokes it for you —
but it must remain executable and reachable on disk (see
[Runner path](#the-runner-path-wrunner) below).

## Requirements

| Requirement | Why | Mandatory? |
|---|---|---|
| **PHP >= 8.4** | modern language features (`readonly` classes, first-class callable syntax, named args) | ✅ |
| **`proc_open`** | spawns the worker processes (core function) | ✅ |
| **`ext-pcntl`** | `pcntl_fork()` for [detached mode](09-detached-mode.md) | ✅ (Composer) |
| **`ext-posix`** | `posix_kill()` (signals) and `posix_setsid()` (detached mode) | ✅ (Composer) |
| **`opis/closure` ^4.5** | safe serialization of closures / anonymous classes and signed payloads | ✅ (Composer) |
| **`ext-shmop`** | only for the [shared-memory transport](08-payload-transports.md) | ⚪ optional |

`ext-pcntl` and `ext-posix` are standard, lightweight POSIX extensions bundled
with almost every Linux/macOS PHP CLI — nothing exotic. There is **no** ZTS build
requirement and **no** heavy extension (swoole/parallel/pthreads) involved.

> **What each dependency actually gates.** The bare spawn/wait path
> (`start()` → `join()`/`reap()`) only needs `proc_open`. `ext-posix` powers
> signal control (`pause`, `resume`, `interrupt`, `terminate`, `kill`, and the
> zombie-aware [`Signal`](../src/Signal.php) helper); `ext-pcntl` powers detached
> mode's `fork`. The package requires both so the full feature set always works —
> but if a platform lacks one, only the corresponding feature is affected.

`ext-shmop` is checked at runtime: [`ShmTransport`](08-payload-transports.md)
throws a clear `ThreadException` ("ShmTransport requires ext-shmop.") on both the
staging and receiving side if the extension is missing — never a fatal error.

## Environment notes

### PHP-FPM / web SAPI

`proc_open` must be permitted (not listed in `disable_functions`). Under FPM,
`PHP_BINARY` points at the **FPM** binary, not a CLI one — running your worker
through that would be wrong. The default [`AdaptiveEngine`](07-the-engine.md)
detects a non-CLI SAPI and resolves a real PHP CLI binary from `PHP_BINDIR`
instead. If detection fails in an unusual setup, set the path explicitly with a
[`ManualEngine`](07-the-engine.md):

```php
Thread::bindEngine(
    (new ManualEngine())
        ->withBinaryPath('/usr/bin/php')
        ->withRunnerPath(__DIR__ . '/vendor/flytachi/winter-thread/wRunner')
        ->withTransport(new \Flytachi\Winter\Thread\Payload\PipeTransport())
);
```

> A `ManualEngine` configures **nothing** for you: `transport`, `binaryPath` and
> `runnerPath` must all be set or the engine throws when they are accessed. Use it
> only when you deliberately want full control; otherwise the `AdaptiveEngine`
> handles FPM correctly on its own.

### The runner path (`wRunner`)

The child is bootstrapped by the `wRunner` script shipped in the package root.
The `AdaptiveEngine` locates it automatically relative to the installed package
(`vendor/flytachi/winter-thread/wRunner`). Two situations need attention:

- **Phar / relocated deployments.** If your code is packed into a `.phar` or the
  vendor directory is not on a normal filesystem path, the script may not be
  directly executable. Point a `ManualEngine` at a real on-disk copy with
  `->withRunnerPath(...)`.
- **`open_basedir`.** The binary and runner paths must be inside any configured
  `open_basedir`.

### Windows

The engine targets POSIX (signals, `setsid`, `/proc`). It is developed and tested
on Linux and macOS. Windows is not supported.

### Containers

If you run [detached](09-detached-mode.md) tasks with your app as **PID 1** in a
container, add a reaping init (`docker run --init`, or `init: true` in Compose) so
orphaned workers are collected. Without it, detached workers reparent to your app
(PID 1) which does not reap them, and they accumulate as zombies. Attached
tasks that you `join()`/`reap()` do **not** need this. Details in
[9. Detached Mode](09-detached-mode.md).

## Verify your install

```php
<?php
require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

$thread = new Thread(new class implements Runnable {
    public function run(array $args): void { /* nothing */ }
});

echo 'PID:  ' . $thread->start() . PHP_EOL;
echo 'exit: ' . $thread->join() . PHP_EOL; // 0
```

Expected output is a numeric PID followed by `exit: 0`. Anonymous classes work
because `opis/closure` is a hard dependency — you are not restricted to named
task classes (though named classes are recommended for readable process titles
and simpler debugging).
