# 2. Installation & Requirements

## Install

```bash
composer require flytachi/winter-thread
```

Installing the package also exposes the child bootstrap script as
`vendor/bin/wRunner`.

## Requirements

| Requirement | Why | Mandatory? |
|---|---|---|
| **PHP >= 8.4** | modern language features (readonly, enums, first-class callables) | ✅ |
| **`proc_open`** | spawns the worker processes (core function) | ✅ |
| **`ext-pcntl`** | `fork` for detached mode | ✅ (Composer) |
| **`ext-posix`** | `posix_kill` (signals) and `setsid` (detached mode) | ✅ (Composer) |
| **`opis/closure` ^4.5** | safe serialization of closures / anonymous classes and signed payloads | ✅ (Composer) |
| **`ext-shmop`** | only for the shared-memory transport | ⚪ optional |

`ext-pcntl` and `ext-posix` are standard, lightweight POSIX extensions bundled
with almost every Linux/macOS PHP CLI — nothing exotic. There is **no** ZTS build
requirement and **no** heavy extension (swoole/parallel/pthreads) involved.

> Note: strictly speaking, the *bare* spawn/wait path (`start()`/`join()`/`reap()`)
> only needs `proc_open`. `ext-posix` is used for signal control (`pause`,
> `kill`, …) and `ext-pcntl` for detached mode; the package requires both so the
> full feature set always works.

## Environment notes

**PHP-FPM / web SAPI.** `proc_open` must be permitted (not listed in
`disable_functions`). Under FPM, `PHP_BINARY` points at the FPM binary, not the
CLI one; the default [`AdaptiveEngine`](06-the-engine.md) detects this and
resolves a real PHP CLI binary. If detection fails in an unusual setup, set the
path explicitly with a [`ManualEngine`](06-the-engine.md):

```php
Thread::bindEngine((new ManualEngine())->withBinaryPath('/usr/bin/php') /* … */);
```

**Windows.** The engine targets POSIX (signals, `setsid`, `/proc`). It is
developed and tested on Linux and macOS.

**Containers.** If you run detached tasks with your app as **PID 1** in a
container, add a reaping init (`docker run --init`, or `init: true` in Compose) so
orphaned workers are collected. See [8. Detached Mode](08-detached-mode.md).

## Verify

```php
<?php
require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

$thread = new Thread(new class implements Runnable {
    public function run(array $args): void { /* nothing */ }
});
echo 'PID: ' . $thread->start() . PHP_EOL;
echo 'exit: ' . $thread->join() . PHP_EOL; // 0
```

Anonymous classes work because `opis/closure` is a hard dependency.
