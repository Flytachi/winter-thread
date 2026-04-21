# Winter Thread: A Modern Process Control Library for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-thread.svg)](https://packagist.org/packages/flytachi/winter-thread)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-thread.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-thread)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

**Winter Thread** provides a clean, object-oriented API for running and controlling background processes in PHP, simulating a traditional threading model for parallel and long-running tasks.

It abstracts away the complexities of `proc_open` and POSIX signals into a powerful and easy-to-use interface.

## Key Features

- **Fluent, Object-Oriented API**: Manage background processes as objects.
- **Full Process Control**: `start()`, `join()`, `pause()`, `resume()`, `terminate()`, and `kill()`.
- **Advanced Process Naming**: Identify your processes easily with namespaces, names, and tags.
- **Safe by Default**: Output goes to `/dev/null` by default — no Broken pipe risk for fire-and-forget jobs.
- **Swoole / Event-Loop Compatible**: Three payload delivery modes (`PIPE`, `TEMP_FILE`, `SHM`) to avoid fd corruption under `SWOOLE_HOOK_ALL`.
- **Extensible**: Easily override the runner script for deep framework integration.
- **Java-like API**: Familiar method names like `isAlive()` and `join()` for an easy learning curve.

## Requirements

- PHP >= 8.1
- `ext-pcntl`
- `ext-posix`
- `opis/closure` ^4.5 (required; enables safe serialization of anonymous classes and closures)

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

## Swoole / Event-Loop Compatibility

Under **Swoole** with `SWOOLE_HOOK_ALL`, stdin pipes created by `proc_open` are intercepted
and their file descriptors leak into Swoole's internal table, causing `Bad file descriptor`
errors on subsequent requests.

Configure the payload mode **once at bootstrap** to avoid pipe fds entirely:

```php
use Flytachi\Winter\Thread\Thread;

// bootstrap.php — loaded by every Swoole worker process
if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() !== -1) {
    // PAYLOAD_TEMP_FILE: payload written to a temp file, unlinked immediately — no ext needed
    // PAYLOAD_SHM:       payload in shared memory (RAM only) — requires ext-shmop
    Thread::bindPayloadMode(
        extension_loaded('shmop')
            ? Thread::PAYLOAD_SHM
            : Thread::PAYLOAD_TEMP_FILE
    );
}
```

After that, `Thread::start()` works identically — no other code changes needed.

| Mode | Delivery | Parent pipe fd | Requires |
|---|---|---|---|
| `PAYLOAD_PIPE` | stdin pipe (default) | yes | — |
| `PAYLOAD_TEMP_FILE` | temp file as stdin | **none** | — |
| `PAYLOAD_SHM` | shared memory | **none** | `ext-shmop` |

See [6. Payload Modes](docs/06-payload-modes.md) for full details, security notes, and the
isolation guarantee for concurrent calls.

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

```bash
composer install
composer test
# or with human-readable output:
composer test-detail
```

## Documentation

Full documentation is in the [/docs](docs) directory:

- [1. Introduction](docs/01-introduction.md)
- [2. Installation and Requirements](docs/02-installation-and-requirements.md)
- [3. Basic Usage](docs/03-basic-usage.md)
- [4. Debugging and Output Handling](docs/04-debugging-and-output.md)
- [5. API Reference](docs/05-api-reference.md)
- [6. Payload Modes (Swoole / Event-Loop Compatibility)](docs/06-payload-modes.md)

## Contributing

Contributions are welcome! Please submit a pull request or open an issue for bugs, questions, or feature requests.

## License

This library is open-source software licensed under the [MIT license](LICENSE).
