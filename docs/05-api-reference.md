# 5. API Reference

This document provides a complete reference for the `Thread` class public API.

## Constructor

### `__construct(Runnable $runnable, string $namespace = '', ?string $name = null, ?string $tag = null)`

Creates a new Thread instance.

| Parameter   | Type       | Default | Description                                                              |
|-------------|------------|---------|--------------------------------------------------------------------------|
| `$runnable` | `Runnable` | -       | The task object to be executed in the new process.                       |
| `$namespace`| `string`   | `''`    | A logical grouping for the process (e.g., "Billing", "Notifications").   |
| `$name`     | `?string`  | `null`  | The specific name for this task. Auto-generated from class name if null. |
| `$tag`      | `?string`  | `null`  | An optional tag to distinguish this specific process instance.           |

---

## Instance Methods

### `start(array $arguments = [], bool $debugMode = false, ?string $outputTarget = null): int`

Starts the execution of the Runnable task in a new background process.

| Parameter       | Type      | Default | Description                                                                 |
|-----------------|-----------|---------|-----------------------------------------------------------------------------|
| `$arguments`    | `array`   | `[]`    | Custom arguments passed to the child process (prefixed with `--arg-`).      |
| `$debugMode`    | `bool`    | `false` | If true, enables debug mode with error reporting.                           |
| `$outputTarget` | `?string` | `null`  | Path to log file, or null for piped output.                                 |

**Returns:** `int` - The PID of the newly created process.

**Throws:** `ThreadException` - If the process fails to start.

---

### `join(int $timeout = 0): ?int`

Waits for the child process to complete (blocks the current script).

| Parameter  | Type  | Default | Description                                       |
|------------|-------|---------|---------------------------------------------------|
| `$timeout` | `int` | `0`     | Maximum seconds to wait. 0 = wait indefinitely.   |

**Returns:** `int|null` - Exit code of the process, null if timeout reached, -1 if already terminated.

---

### `isAlive(): bool`

Checks if the child process is currently running.

**Returns:** `bool` - True if running, false otherwise.

---

### `pause(): bool`

Pauses the process by sending a `SIGSTOP` signal.

**Returns:** `bool` - True if signal sent successfully.

---

### `resume(): bool`

Resumes a paused process by sending a `SIGCONT` signal.

**Returns:** `bool` - True if signal sent successfully.

---

### `interrupt(): bool`

Sends an interrupt signal (`SIGINT`) to the process. Equivalent to Ctrl+C.

**Returns:** `bool` - True if signal sent successfully.

---

### `terminate(): bool`

Requests graceful termination by sending a `SIGTERM` signal.

**Returns:** `bool` - True if signal sent successfully.

---

### `kill(): bool`

Forcefully terminates the process by sending a `SIGKILL` signal.

**Returns:** `bool` - True if signal sent successfully.

---

### `readOutput(): string`

Reads standard output (STDOUT) from the process. Only works if started with `$outputTarget = null`.

**Returns:** `string` - Content from STDOUT since last read.

---

### `readError(): string`

Reads standard error (STDERR) from the process. Only works if started with `$outputTarget = null`.

**Returns:** `string` - Content from STDERR since last read.

---

### `getPid(): ?int`

Gets the Process ID of the child process.

**Returns:** `int|null` - The PID, or null if not started.

---

### `getNamespace(): string`

Gets the namespace of the process.

**Returns:** `string` - The logical namespace.

---

### `getName(): string`

Gets the name of the task.

**Returns:** `string` - The task name.

---

### `getTag(): ?string`

Gets the optional tag of the process instance.

**Returns:** `string|null` - The tag, or null if not set.

---

## Static Methods

### `bindSerSecurity(string $serSecurityKey): void`

Sets the secret key for secure closure serialization via `opis/closure`.

```php
Thread::bindSerSecurity('your-secret-key');
```

---

### `bindRunner(string $runnerScriptPath): void`

Overrides the default path to the runner script.

```php
Thread::bindRunner('/path/to/custom/runner');
```

---

### `bindBinaryPath(string $binaryPath): void`

Sets a custom path to the PHP CLI binary. Useful when running under PHP-FPM or CGI
where `PHP_BINARY` points to the web handler instead of CLI.

```php
Thread::bindBinaryPath('/usr/bin/php');
```

---

### `getRunnerScriptPath(): string`

Gets the configured path to the runner script.

**Returns:** `string` - Path to the runner script.

---

### `getSerSecurity(): ?DefaultSecurityProvider`

Gets the security provider for Opis/Closure serialization.

**Returns:** `DefaultSecurityProvider|null` - The provider if configured, otherwise null.
