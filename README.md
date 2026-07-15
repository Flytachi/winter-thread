# Winter Thread: A Modern Process Control Library for PHP

[![Tests](https://img.shields.io/github/actions/workflow/status/flytachi/winter-thread/ci.yml?branch=main&label=tests)](https://github.com/flytachi/winter-thread/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-thread.svg)](https://packagist.org/packages/flytachi/winter-thread)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-thread.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-thread)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

**Winter Thread** is a *process engine* for PHP: a clean, object-oriented,
Java-like API for running and controlling background tasks as isolated OS
processes — for parallel and long-running work.

> **It's an engine — the foundation you build on.** A small, dependable core,
> not a batteries-included framework: the layer your **queues, pools, schedulers
> and workers** sit *on top of*. You bring the higher-level concurrency; the engine
> handles the hard, boring parts — spawning, signals, isolation, transports.
>
> **A `Thread` here is a *process*, not a PHP thread.** The name is a deliberate nod
> to a familiar API — just as Python's `multiprocessing.Process` mirrors its
> threading interface. Every `Thread` is one fully isolated OS process wearing a
> clean, thread-like face (`start()`, `join()`, `isAlive()`) — so there's no shared
> state to corrupt and nothing to leak between tasks.

**No heavy extensions.** Unlike `pthreads`, `ext-parallel`, or Swoole, it needs
no ZTS build and no exotic runtime — just `proc_open` and the standard POSIX
extensions (`ext-pcntl`, `ext-posix`) that ship with nearly every PHP install.
Each task runs in a fresh, isolated PHP process, so there is no shared state to
corrupt and no inherited connections to break.

## Key Features

- **No heavy extensions**: No swoole / parallel / pthreads, no ZTS build — just `proc_open` + standard POSIX. Runs on a normal PHP install.
- **Clean process isolation**: Each task runs in a brand-new PHP process — no inherited DB connections, sockets, or global state to corrupt.
- **Fluent, Object-Oriented API**: Manage background processes as objects.
- **Full Process Control**: `start()`, `join()`, `pause()`, `resume()`, `terminate()`, and `kill()`.
- **Advanced Process Naming**: Identify your processes easily with namespaces, names, and tags.
- **Safe by Default**: Output goes to `/dev/null` by default — no Broken pipe risk for fire-and-forget jobs.
- **Swoole / Event-Loop Compatible**: Pluggable payload transports (pipe, temp-file, shared-memory); the default engine auto-detects an active Swoole runtime and avoids fd corruption under `SWOOLE_HOOK_ALL`.
- **Zombie-free fire-and-forget**: Optional detached mode (`fork` + `setsid`) reparents workers to init, so long-lived parents (FPM, daemons) never accumulate zombies.
- **Pluggable backend**: Swap the payload transport or the whole spawn strategy through a single `Launcher` — build custom backends (Docker, SSH, …) without touching `Thread`.
- **Java-like API**: Familiar method names like `isAlive()` and `join()` for an easy learning curve.

## Requirements

- PHP >= 8.4
- `ext-pcntl`
- `ext-posix`
- `opis/closure` ^4.5 (required; enables safe serialization of anonymous classes and closures)
- `ext-shmop` (optional; only for the shared-memory transport)

## Installation

```bash
composer require flytachi/winter-thread
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use Flytachi\Winter\Thread\Runnable;
use Flytachi\Winter\Thread\Thread;

// 1. Define your task by implementing Runnable.
//    Logic inside run() executes in a separate process.
class VideoProcessingTask implements Runnable {
    public function __construct(private string $videoFile) {}

    public function run(array $args): void {
        $quality = $args['quality'] ?? 'high';
        // output goes to /dev/null by default — use outputTarget for logging
        sleep(5); // simulate encoding
    }
}

// 2. Create a Thread with optional metadata for OS process identification.
$thread = new Thread(
    new VideoProcessingTask('movie.mp4'),
    'Media',          // namespace
    'VideoProcessor', // name
    'job-42'          // tag
);

// 3. Start the thread.
//    Default outputTarget='/dev/null' — safe for fire-and-forget.
//    Pass outputTarget: '/path/to/file.log' to capture output.
//    Pass outputTarget: null ONLY when actively reading via readOutput().
$pid = $thread->start(['quality' => 'hd']);
echo "Processing started (PID: $pid)\n";

// Main script continues immediately.
echo "Doing other work...\n";

// 4. Optionally wait for the task to finish.
$exitCode = $thread->join();
echo "Task finished with exit code: $exitCode\n";
```

## Configuration — the Launcher

Configuration goes through a single `Launcher`, bound **once at bootstrap** with
`Thread::bindLauncher()`. When you bind nothing, a self-configuring **`CliLauncher`**
is used (`CliLauncher::adaptive()`), adapting to the current environment (CLI / FPM).

```php
use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Payload\TempFileTransport;

// Zero-config: a self-configuring CliLauncher is the default — nothing to do.
$thread = new Thread(new MyTask());
$thread->start();

// Explicit configuration when you need it:
Thread::bindLauncher(new CliLauncher(
    binaryPath: '/usr/bin/php',
    runnerPath: __DIR__ . '/vendor/flytachi/winter-thread/wRunner',
    transport:  new TempFileTransport(),      // omit to auto-detect per launch
    secret:     'your-signing-secret',        // signs serialized closures
));

// Custom backend (Docker/SSH/…): implement Launcher and bind it directly.
Thread::bindLauncher(new MyCustomLauncher());
```

### Swoole / Event-Loop Compatibility

When its transport is left unset, `CliLauncher::adaptive()` picks a **pipe-free**
transport (`TempFileTransport`) if it detects an active Swoole runtime — pipe file
descriptors from `proc_open` do not survive `SWOOLE_HOOK_ALL` intact.

> **Swoole support is under active development.** A safe transport is necessary but
> not sufficient for running from *inside a live Swoole coroutine worker*: native
> `proc_open` contends with the Swoole reactor over the process file-descriptor
> table. Treat in-coroutine dispatch as experimental for now. Plain CLI and FPM are
> unaffected. See [docs/08](docs/08-payload-transports.md#swoole--event-loop-compatibility).

| Transport | Delivery | Parent pipe fd | Requires |
|---|---|---|---|
| `PipeTransport` | stdin pipe (default in CLI) | yes | — |
| `TempFileTransport` | temp file as stdin | **none** | — |
| `ShmTransport` | shared memory | **none** | `ext-shmop` |

## Detached (zombie-free) fire-and-forget

For a long-lived parent (FPM worker, daemon) that dispatches background tasks and never
joins them, pass `detached: true`. The launcher exits immediately and the real worker is
reparented to init (`pid 1`), so no zombie ever accumulates under the parent:

```php
$thread = new Thread(new SendEmailBatch($ids));
$thread->start(detached: true);   // returns at once; worker owned by init
```

Signal control still works via the worker's self-reported PID (write `getmypid()` from
inside the task to your own store), since the engine's control model is PID-based.

---

## Output Modes

| `$outputTarget`         | Use case                                                     |
|-------------------------|--------------------------------------------------------------|
| `'/dev/null'` (default) | Fire-and-forget: safe, output discarded                      |
| `'/path/to/file.log'`   | Persistent logging for staging/production                    |
| `null` (explicit)       | Piped to parent: read via `readOutput()` / `readError()`     |

> **Note:** With `null`, `join()` and `reap()` drain the pipes internally while they
> wait, so a bare `join()` never deadlocks on a large output — and `readOutput()`
> after it returns the full buffered output. Use an explicit `readOutput()` poll loop
> only when you want the output **live** as it is produced.

## Process Control

```php
$thread->pause();     // SIGSTOP — suspend execution
$thread->resume();    // SIGCONT — resume after pause
$thread->terminate(); // SIGTERM — graceful shutdown request
$thread->kill();      // SIGKILL — force kill (last resort)
$thread->interrupt(); // SIGINT  — Ctrl+C equivalent
$thread->isAlive();   // bool    — check if still running
```

## Running Tests

Tests come in two tiers (mirroring the `winter-kernel` layout):

**Default** — runs on any machine; unsupported extensions self-skip:

```bash
composer install
composer test            # base (class correctness) + working (scenarios)
composer test-base       # only unit-level class correctness
composer test-working    # only end-to-end scenarios
composer test-detail     # human-readable (testdox) output
```

**Containered** — heavy, environment-specific checks (leak / timing / nested / battle-run,
with Cli / FPM / Swoole), run inside Docker across a list of PHP versions:

```bash
tests/run-container.sh              # default versions: 8.4 8.5
tests/run-container.sh 8.4          # a single version
tests/run-container.sh 8.4 8.5 8.6  # a custom list

# Or, inside an environment that already has swoole/shmop:
composer test-container             # phpunit --testsuite container
```

CI (`.github/workflows/ci.yml`) runs the default suite via `setup-php` and the container
suite via the bundled `tests/docker/Dockerfile`, on a PHP 8.4 / 8.5 matrix.

## Documentation

Full documentation lives in [`/docs`](docs/README.md):

1. [Introduction](docs/01-introduction.md) — philosophy, the no-heavy-ext story, when to use it
2. [Installation & Requirements](docs/02-installation-and-requirements.md)
3. [Quickstart](docs/03-quickstart.md) — a complete parallel example in 5 minutes
4. [Basic Usage](docs/04-basic-usage.md)
5. [Output & Debugging](docs/05-output-and-debugging.md)
6. [Process Control & Lifecycle](docs/06-process-control.md) — signals, graceful shutdown
7. [The Launcher](docs/07-the-launcher.md)
8. [Payload Transports](docs/08-payload-transports.md)
9. [Detached Mode](docs/09-detached-mode.md)
10. [Security](docs/10-security.md)
11. [Architecture & Internals](docs/11-architecture.md)
12. [Patterns](docs/12-patterns.md) — pools, returning results, retries
13. [Troubleshooting](docs/13-troubleshooting.md)
14. [API Reference](docs/14-api-reference.md)
15. [Testing](docs/15-testing.md)

## Contributing

Contributions are welcome! Please submit a pull request or open an issue for bugs, questions, or feature requests.


## License

This library is open-source software licensed under the [MIT license](LICENSE).