# 6. Payload Modes

By default, Winter Thread serializes the `Runnable` object and delivers it to the child
process through a **stdin pipe** (`proc_open` descriptor 0). This works perfectly in
standard PHP-FPM and CLI environments.

However, in environments like **Swoole** where `SWOOLE_HOOK_ALL` intercepts all file
descriptor operations and wraps them in coroutines, the pipe descriptor leaks into
Swoole's internal fd table and causes a cascade failure:

1. Parent writes payload and calls `fclose($pipe)`.
2. Swoole tries to clean up the fd via `socket_free_defer`, treating it as a socket — gets `EBADF`.
3. The fd stays "dirty" in Swoole's internal table.
4. On the next request the kernel reuses the same fd number for a new pipe.
5. Swoole sees it as already registered → `posix_spawn() failed: Bad file descriptor`.

Winter Thread solves this with two alternative payload delivery strategies that create
**zero parent-side pipe fds**.

---

## Available Modes

| Constant | Delivery method | Parent fd | Requires |
|---|---|---|---|
| `Thread::PAYLOAD_PIPE` | stdin pipe (default) | `pipe fd` | — |
| `Thread::PAYLOAD_TEMP_FILE` | temp file as stdin | none | writable `sys_get_temp_dir()` |
| `Thread::PAYLOAD_SHM` | System V shared memory | none | `ext-shmop` |

---

## Configuring the Mode

Call `Thread::bindPayloadMode()` **once during application bootstrap**, before any threads
are started. The mode is global and applies to all subsequent `Thread::start()` calls.

```php
use Flytachi\Winter\Thread\Thread;

Thread::bindPayloadMode(Thread::PAYLOAD_TEMP_FILE);
// or
Thread::bindPayloadMode(Thread::PAYLOAD_SHM);
// or reset to default
Thread::bindPayloadMode(Thread::PAYLOAD_PIPE);
```

**Throws** `ThreadException` if:
- The mode string is not one of the three constants.
- `PAYLOAD_SHM` is requested but `ext-shmop` is not loaded.

---

## Mode: `PAYLOAD_TEMP_FILE`

The payload is written to a temporary file in `sys_get_temp_dir()` before `proc_open` is
called. The file is opened with `0600` permissions and unlinked from the filesystem
**immediately after `proc_open` succeeds**. The child process holds the file descriptor
open and reads the full payload through it; no directory entry remains on disk.

**No pipe fd is ever created in the parent process.**

### When to use

- Running inside **Swoole** coroutines (`SWOOLE_HOOK_ALL` enabled).
- Running inside **ReactPHP**, **Amp**, or any other event loop that hooks file descriptors.
- Any environment where open pipe fds cause issues.

### Requirements

- `sys_get_temp_dir()` must be writable (standard on all systems).
- No extra PHP extensions needed.

### Example: Swoole bootstrap

```php
<?php

use Flytachi\Winter\Thread\Thread;

// In your Swoole server bootstrap (before Server::start()):
if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() !== -1) {
    Thread::bindPayloadMode(Thread::PAYLOAD_TEMP_FILE);
}
```

### Example: always-on for Swoole workers

```php
<?php
// bootstrap.php — loaded by every Swoole worker

use Flytachi\Winter\Thread\Thread;

Thread::bindPayloadMode(Thread::PAYLOAD_TEMP_FILE);
Thread::bindBinaryPath('/usr/bin/php');
Thread::bindSerSecurity($_ENV['APP_SECRET']);
```

---

## Mode: `PAYLOAD_SHM`

The payload is written to a **System V shared memory segment** (`shmop_open` with flag
`'n'`, permissions `0600`). The segment key is passed to the child process via the
`--shmkey` CLI argument. The child reads the full payload from shared memory, immediately
calls `shmop_delete()`, and then proceeds normally. `join()` calls `shmop_delete()` as a
safety fallback if the child process exits without cleanup (e.g. on a fatal crash).

**No pipe fd is ever created in the parent process.** Stdin is set to `/dev/null`.

### When to use

- Same as `PAYLOAD_TEMP_FILE`, with the added benefit of **no disk I/O** — suitable for
  very large payloads or high-throughput scenarios where temp file write latency matters.
- Shared memory is allocated and freed entirely in RAM.

### Requirements

- `ext-shmop` PHP extension must be loaded.

### Example: Swoole bootstrap with SHM

```php
<?php

use Flytachi\Winter\Thread\Thread;

if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() !== -1) {
    // Prefer SHM if ext-shmop is available, fall back to temp file
    if (extension_loaded('shmop')) {
        Thread::bindPayloadMode(Thread::PAYLOAD_SHM);
    } else {
        Thread::bindPayloadMode(Thread::PAYLOAD_TEMP_FILE);
    }
}
```

---

## Choosing the Right Mode

```
Are you running inside Swoole / ReactPHP / Amp?
│
├── No  → PAYLOAD_PIPE (default, no action needed)
│
└── Yes → Is ext-shmop available and payload is large or high-frequency?
          │
          ├── Yes → PAYLOAD_SHM   (zero disk I/O, RAM only)
          │
          └── No  → PAYLOAD_TEMP_FILE  (no extra extension, works everywhere)
```

In practice, **`PAYLOAD_TEMP_FILE` is the safest universal choice** for Swoole environments.
Use `PAYLOAD_SHM` only when you have measured a bottleneck in temp file creation.

---

## Security

Both alternative modes enforce `0600` permissions on the payload storage:

- **`PAYLOAD_TEMP_FILE`**: `chmod(0600)` is called immediately after `tempnam()`, before
  any data is written. The file is unlinked from the filesystem within microseconds of
  `proc_open` returning.
- **`PAYLOAD_SHM`**: `shmop_open($key, 'n', 0600, $size)` — only the owning user (the
  web server / PHP process user) can attach to the segment.

---

## Isolation Guarantee

Each `Thread::start()` call creates its own independent storage:

- `PAYLOAD_TEMP_FILE`: `tempnam()` guarantees a unique path per call.
- `PAYLOAD_SHM`: a unique key is generated via `crc32(uniqid(...) + counter)` with
  up to 5 collision retries.

The storage reference (`$tmpPath` / `$shmKey`) is a **local variable** on the `start()`
call stack — never an instance property — so concurrent calls from different `Thread`
objects or repeated calls on the same object cannot interfere with each other.

```php
// Safe: each start() creates its own isolated temp file or shm segment
Thread::bindPayloadMode(Thread::PAYLOAD_TEMP_FILE);

$threads = [];
for ($i = 0; $i < 100; $i++) {
    $threads[] = (new Thread(new MyTask($i)))->start();
}
```
