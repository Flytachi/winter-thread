# Winter Thread: A Modern Process Control Library for PHP

[![Tests](https://img.shields.io/github/actions/workflow/status/flytachi/winter-thread/ci.yml?branch=main&label=tests)](https://github.com/flytachi/winter-thread/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-thread.svg)](https://packagist.org/packages/flytachi/winter-thread)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-thread.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-thread)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

**Winter Thread** is a *process engine* for PHP: a clean, object-oriented,
Java-like API for running and controlling background tasks as isolated OS
processes — for parallel and long-running work.

**No heavy extensions.** Unlike `pthreads`, `ext-parallel`, or Swoole, it needs
no ZTS build and no exotic runtime — just `proc_open` and the standard POSIX
extensions (`ext-pcntl`, `ext-posix`) that ship with nearly every PHP install.
Each task runs in a fresh, isolated PHP process, so there is no shared state to
corrupt and no inherited connections to break.

It is deliberately a low-level **engine** — a small, dependable core to build
pools, queues and schedulers on — that abstracts away `proc_open` and POSIX
signals behind a friendly API.

## Key Features

- **No heavy extensions**: No swoole / parallel / pthreads, no ZTS build — just `proc_open` + standard POSIX. Runs on a normal PHP install.
- **Clean process isolation**: Each task runs in a brand-new PHP process — no inherited DB connections, sockets, or global state to corrupt.
- **Fluent, Object-Oriented API**: Manage background processes as objects.
- **Full Process Control**: `start()`, `join()`, `pause()`, `resume()`, `terminate()`, and `kill()`.
- **Advanced Process Naming**: Identify your processes easily with namespaces, names, and tags.
- **Safe by Default**: Output goes to `/dev/null` by default — no Broken pipe risk for fire-and-forget jobs.
- **Swoole / Event-Loop Compatible**: Pluggable payload transports (pipe, temp-file, shared-memory); the default engine auto-detects an active Swoole runtime and avoids fd corruption under `SWOOLE_HOOK_ALL`.
- **Zombie-free fire-and-forget**: Optional detached mode (`fork` + `setsid`) reparents workers to init, so long-lived parents (FPM, daemons) never accumulate zombies.
- **Pluggable Engine**: Swap transport, launcher, or child-side runner through a single `Engine` — build custom backends (Docker, SSH, …) without touching `Thread`.
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

## Configuration — the Engine

All configuration goes through a single `Engine`, bound **once at bootstrap** with
`Thread::bindEngine()`. When you bind nothing, the **`AdaptiveEngine`** is used and
self-configures for the current environment (CLI / FPM / Swoole).

```php
use Flytachi\Winter\Thread\Engine\ManualEngine;
use Flytachi\Winter\Thread\Payload\TempFileTransport;

// Zero-config: AdaptiveEngine is the default — nothing to do.
$thread = new Thread(new MyTask());
$thread->start();

// Explicit configuration when you need it:
Thread::bindEngine(
    (new ManualEngine())
        ->withTransport(new TempFileTransport())
        ->withBinaryPath('/usr/bin/php')
        ->withRunnerPath(__DIR__ . '/vendor/flytachi/winter-thread/wRunner')
        ->withSecurity('your-signing-secret')   // signs serialized closures
        ->withLauncher(new MyCustomLauncher())   // optional: custom backend
);
```

### Swoole / Event-Loop Compatibility

Under **Swoole** with `SWOOLE_HOOK_ALL`, stdin pipes created by `proc_open` are intercepted
and their file descriptors leak into Swoole's internal table, causing `Bad file descriptor`
errors. The `AdaptiveEngine` **detects an active Swoole runtime automatically** and switches
to a file/shared-memory transport, so no configuration is needed. Under Swoole, also prefer
file output over `outputTarget: null` (the output pipes are subject to the same hooks).

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
| `null` (explicit)       | Interactive: parent polls `readOutput()` / `readError()`     |

> **Important:** Never pass `null` unless the parent actively drains the pipe in a polling
> loop. A full buffer causes a **Broken pipe** that silently kills the background job.

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
3. [Basic Usage](docs/03-basic-usage.md)
4. [Output & Debugging](docs/04-output-and-debugging.md)
5. [Process Control & Lifecycle](docs/05-process-control.md)
6. [The Engine](docs/06-the-engine.md)
7. [Payload Transports](docs/07-payload-transports.md)
8. [Detached Mode](docs/08-detached-mode.md)
9. [Security](docs/09-security.md)
10. [Architecture & Internals](docs/10-architecture.md)
11. [API Reference](docs/11-api-reference.md)
12. [Testing](docs/12-testing.md)

## Contributing

Contributions are welcome! Please submit a pull request or open an issue for bugs, questions, or feature requests.


## License

This library is open-source software licensed under the [MIT license](LICENSE).