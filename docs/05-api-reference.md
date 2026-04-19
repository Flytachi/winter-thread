# 5. API Reference

Complete reference for all public classes and methods in Winter Thread.

---

## `Thread`

The main class. Manages a single background process that executes a `Runnable` task.

### Constructor

```php
new Thread(
    Runnable $runnable,
    string   $namespace = '',
    ?string  $name      = null,
    ?string  $tag       = null
)
```

| Parameter    | Type       | Default | Description                                                                 |
|--------------|------------|---------|-----------------------------------------------------------------------------|
| `$runnable`  | `Runnable` | —       | The task to execute in the child process.                                   |
| `$namespace` | `string`   | `''`    | Logical group for process identification (e.g. `"Billing"`).               |
| `$name`      | `?string`  | `null`  | Task name. Auto-derived from the class short name when `null`.              |
| `$tag`       | `?string`  | `null`  | Instance tag to distinguish runs (e.g. `"job-42"`, `"user-123"`).          |

The namespace, name, and tag appear in the OS process title as:
`WinterThread <namespace> -> <name>@<tag>`

---

### Instance Methods

#### `start(array $arguments = [], bool $debugMode = false, ?string $outputTarget = '/dev/null'): int`

Serializes the `Runnable`, launches a child PHP process via `proc_open`, and returns immediately.

| Parameter       | Type      | Default        | Description                                                                 |
|-----------------|-----------|----------------|-----------------------------------------------------------------------------|
| `$arguments`    | `array`   | `[]`           | Associative array of custom run arguments. Scalar values and booleans only. `true` creates a valueless flag; `false`/`null` are skipped. Available in `run()` via the `$args` parameter. |
| `$debugMode`    | `bool`    | `false`        | `true` enables PHP error reporting in the child process.                    |
| `$outputTarget` | `?string` | `'/dev/null'`  | Where stdout/stderr go. `'/dev/null'` discards output (safe default for fire-and-forget). `null` pipes output to the parent — only use when actively reading via `readOutput()`/`readError()`. A file path appends output to that file. |

**Returns:** `int` — PID of the child process.

**Throws:** `ThreadException` — if `proc_open` fails.

> **Note:** The default `'/dev/null'` prevents **Broken pipe** errors that occur when a parent
> opens a pipe but never reads from it. Pass `null` explicitly only when you call
> `readOutput()` / `readError()` in a polling loop.

---

#### `join(int $timeout = 0): ?int`

Blocks until the child process terminates.

| Parameter  | Type  | Default | Description                                      |
|------------|-------|---------|--------------------------------------------------|
| `$timeout` | `int` | `0`     | Max seconds to wait. `0` waits indefinitely.     |

**Returns:**
- `int` — exit code (typically `0` for success).
- `null` — timeout reached before termination.
- `-1` — process was never started.

---

#### `isAlive(): bool`

Checks whether the child process is currently running.

**Returns:** `true` if running (including paused), `false` otherwise.

---

#### `pause(): bool`

Pauses the child process by sending `SIGSTOP`. The process remains in the process table
(so `isAlive()` returns `true`) but is suspended by the OS until `resume()` is called.

**Returns:** `true` if the signal was delivered, `false` if the process is not running.

---

#### `resume(): bool`

Resumes a paused process by sending `SIGCONT`.

**Returns:** `true` if the signal was delivered, `false` if the process is not running.

---

#### `interrupt(): bool`

Sends `SIGINT` to the child process (equivalent to Ctrl+C). The process can catch and handle this signal.

**Returns:** `true` if the signal was delivered, `false` if the process is not running.

---

#### `terminate(): bool`

Requests graceful shutdown by sending `SIGTERM`. The child process can catch this signal
to perform cleanup before exiting. If it ignores the signal, the process keeps running.

**Returns:** `true` if the signal was delivered, `false` if the process is not running.

---

#### `kill(): bool`

Forcefully terminates the child process by sending `SIGKILL`. Cannot be caught or ignored.
Use as a last resort after `terminate()` fails.

**Returns:** `true` if the signal was delivered, `false` if the process is not running.

---

#### `readOutput(): string`

Reads buffered stdout from the child process since the last call. Only works when started
with `$outputTarget = null`.

**Returns:** `string` — content read from stdout, or `''` if no pipe is open.

---

#### `readError(): string`

Reads buffered stderr from the child process since the last call. Only works when started
with `$outputTarget = null`.

**Returns:** `string` — content read from stderr, or `''` if no pipe is open.

---

#### `getPid(): ?int`

**Returns:** `int` — PID of the child process, or `null` if not yet started.

---

#### `getNamespace(): string`

**Returns:** `string` — the namespace provided to the constructor.

---

#### `getName(): string`

**Returns:** `string` — the task name (auto-derived or explicitly set).

---

#### `getTag(): ?string`

**Returns:** `string|null` — the tag, or `null` if not set.

---

### Static Methods

#### `Thread::bindSerSecurity(string $serSecurityKey): void`

Sets a secret key for signed serialization of closures and anonymous classes via `opis/closure`.
Call once during application bootstrap before creating any threads that use anonymous classes.

```php
Thread::bindSerSecurity('your-long-secret-key');
```

---

#### `Thread::bindRunner(string $runnerScriptPath): void`

Overrides the path to the runner script (`wExecutor`). Useful for framework integration
where a custom bootstrap is needed in the child process.

```php
Thread::bindRunner('/app/bootstrap/thread-runner.php');
```

---

#### `Thread::bindBinaryPath(string $binaryPath): void`

Sets the path to the PHP CLI binary. Required when running under PHP-FPM or CGI, where
`PHP_BINARY` points to the FPM handler rather than the CLI executable.

```php
Thread::bindBinaryPath('/usr/bin/php8.3');
```

---

#### `Thread::getRunnerScriptPath(): string`

**Returns:** `string` — the configured runner script path (or the default `wExecutor`).

---

#### `Thread::getSerSecurity(): ?DefaultSecurityProvider`

**Returns:** `DefaultSecurityProvider|null` — the Opis/Closure security provider, or `null` if not configured.

---

## `Runnable`

Interface that every background task must implement.

```php
interface Runnable
{
    public function run(array $args): void;
}
```

| Constraint | Detail |
|---|---|
| **Serializable** | The object is serialized and passed to the child process via stdin. Properties must not contain resources (DB connections, file handles, sockets). Initialize those inside `run()`. |
| **`$args`** | Associative array of custom arguments passed via `Thread::start(['key' => 'value'])`. Keys map to `--arg-<key>=<value>` CLI flags. `true` flags arrive as `true`; string values arrive as strings. |

---

## `Signal`

Utility class for sending POSIX signals to arbitrary PIDs.

> **Warning:** Unlike `Thread`, `Signal` does not validate that the PID belongs to your
> process. Due to OS PID reuse, a PID may be reassigned to a different process after the
> original terminates. Use `Thread` methods for reliable lifecycle management; use `Signal`
> only with freshly obtained PIDs.

### Static Methods

| Method | Signal | Description |
|---|---|---|
| `Signal::interrupt(int $pid): bool` | `SIGINT` | Sends interrupt (Ctrl+C). |
| `Signal::termination(int $pid): bool` | `SIGTERM` | Requests graceful shutdown. |
| `Signal::close(int $pid): bool` | `SIGHUP` | Sends hangup (reload or exit depending on the process). |
| `Signal::kill(int $pid): bool` | `SIGKILL` | Forces immediate termination. |
| `Signal::wait(int $pid, int $timeout = 10): bool` | — | Polls until the PID is gone or timeout (seconds) is reached. Returns `true` if the process terminated, `false` on timeout. |
| `Signal::interruptAndWait(int $pid, int $timeout = 10): bool` | `SIGINT` | Sends interrupt, then waits. |
| `Signal::terminationAndWait(int $pid, int $timeout = 10): bool` | `SIGTERM` | Sends SIGTERM, then waits. |
| `Signal::closeAndWait(int $pid, int $timeout = 10): bool` | `SIGHUP` | Sends SIGHUP, then waits. |
| `Signal::isProcessRunning(int $pid): bool` | `0` (probe) | Returns `true` if a process with this PID exists. |

---

## `ThreadException`

Extends `\RuntimeException`. Thrown by `Thread::start()` when `proc_open` fails to launch
the child process (e.g. resource limits, wrong permissions, missing runner script).
