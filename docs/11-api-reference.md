# 11. API Reference

Namespace root: `Flytachi\Winter\Thread`.

---

## `Runnable` (interface)

```php
interface Runnable
{
    public function run(array $args): void;
}
```

The task contract. Implement it and put your logic in `run()`, which executes in
the worker process. `$args` holds per-run values passed to `start()`.

---

## `Thread` (final class)

The facade. Constructing it does not start anything.

### Construction

```php
new Thread(
    Runnable $runnable,
    string   $namespace = '',
    ?string  $name = null,   // auto-derived from the Runnable class if null
    ?string  $tag = null,
)
```

### Static engine binding

```php
Thread::bindEngine(Engine $engine): void   // set the process-wide engine
Thread::engine(): Engine                   // current engine (lazy AdaptiveEngine default)
```

### Lifecycle

```php
start(
    array   $arguments = [],
    bool    $debugMode = false,
    ?string $outputTarget = '/dev/null',
    bool    $detached = false,
): int                     // launches; returns PID. Throws ThreadException if already alive or on failure.

join(int $timeout = 0): ?int   // block until done; exit code, null on timeout, -1 if never started
reap(): bool                   // non-blocking; true if finished/absent (and reaped), false if running
detach(): void                 // stop tracking (non-blocking)
```

### State

```php
getPid(): ?int
isAlive(): bool
getExitCode(): ?int    // set once reaped
```

### Signals *(require ext-posix; return false if not running)*

```php
pause(): bool       // SIGSTOP
resume(): bool      // SIGCONT
interrupt(): bool   // SIGINT
terminate(): bool   // SIGTERM
kill(): bool        // SIGKILL
```

### Output *(only when started with outputTarget: null)*

```php
readOutput(): string   // STDOUT since last read
readError(): string    // STDERR since last read
```

### Metadata

```php
getNamespace(): string
getName(): string
getTag(): ?string
```

---

## `Engine` (interface)

```php
transport(): PayloadTransport
launcher(): Launcher
runner(): Runner
binaryPath(): string
runnerPath(): string
security(): ?DefaultSecurityProvider
```

### `AdaptiveEngine` (final class, default)

```php
new AdaptiveEngine(
    ?string           $secret = null,     // else WINTER_THREAD_SECRET env, else null
    ?PayloadTransport $transport = null,  // else auto (TempFile under Swoole, else Pipe)
    ?string           $binaryPath = null, // else resolved PHP CLI binary
    ?string           $runnerPath = null, // else packaged wRunner
    ?Launcher         $launcher = null,   // else default CliLauncher
)
```

### `ManualEngine` (final class)

Immutable withers; unset required parts throw `ThreadException` when accessed.

```php
withTransport(PayloadTransport $t): static
withBinaryPath(string $path): static
withRunnerPath(string $path): static
withSecurity(string $secret): static
withLauncher(Launcher $l): static
```

---

## `Launcher` (interface)

```php
launch(LaunchSpec $spec): ProcessHandle   // throws ThreadException on failure
```

### `CliLauncher` (final class)

```php
new CliLauncher(
    string           $binaryPath,
    string           $runnerPath,
    PayloadTransport $transport,
    array            $childEnv = [],  // e.g. ['WINTER_THREAD_SECRET' => '…']
)
```

---

## `ProcessHandle` (final class)

The low-level process primitive returned by a launcher; drive it directly from a
pool.

```php
getPid(): int
isAlive(): bool
join(int $timeout = 0): ?int
reap(): bool
detach(): void
getExitCode(): ?int
readOutput(): string
readError(): string
signal(int $signal): bool   // posix_kill(pid, signal); false if not alive
```

`reap()`, `detach()` and `__destruct()` are non-blocking on a live process.

---

## `LaunchSpec` (final readonly class)

```php
new LaunchSpec(
    string  $payload,               // serialized Runnable
    string  $namespace = '',
    string  $name = 'anonymous',
    ?string $tag = null,
    array   $arguments = [],
    bool    $debug = false,
    ?string $output = '/dev/null',
    bool    $detached = false,
)
```

---

## `PayloadTransport` (interface)

```php
stage(string $payload): StagedPayload   // parent: prepare delivery
receive(array $options): string         // child: read the payload back
cleanup(StagedPayload $staged): void    // parent: release staged resources
```

Implementations: `PipeTransport`, `TempFileTransport`, `ShmTransport`
(the last requires `ext-shmop`).

### `StagedPayload` (final readonly class)

```php
new StagedPayload(
    array   $stdinSpec,              // proc_open fd-0 descriptor
    array   $cliArgs = [],           // extra safe launch args (e.g. --shmkey=…)
    ?string $pipePayload = null,     // written to the pipe after launch (pipe transport)
    ?string $unlinkAfterOpen = null, // unlinked after launch (temp-file transport)
    mixed   $ref = null,             // cleanup handle (temp path / shm key)
)
```

---

## `Runner` (interface)

```php
execute(array $options): int   // runs in the child; returns exit code
```

Default: `ProcessRunner` (constructed with an `Engine`, plus an optional error
stream for testing). Handles receive → deserialize+verify → optional daemonize →
run.

---

## `Signal` (final class)

Static POSIX helpers that operate on a raw PID. `isProcessRunning()` correctly
treats zombie processes (state `Z`) as not running, on both Linux (`/proc`) and
macOS (`ps`).

```php
Signal::isProcessRunning(int $pid): bool
Signal::interrupt(int $pid): bool      // SIGINT
Signal::termination(int $pid): bool    // SIGTERM
Signal::close(int $pid): bool          // SIGHUP
Signal::kill(int $pid): bool           // SIGKILL
Signal::wait(int $pid, int $timeout = 10): bool
Signal::interruptAndWait(int $pid, int $timeout = 10): bool
Signal::terminationAndWait(int $pid, int $timeout = 10): bool
Signal::closeAndWait(int $pid, int $timeout = 10): bool
```

> ⚠️ Raw PIDs are subject to PID reuse by the OS. For reliable tracking prefer a
> `Thread`/`ProcessHandle`, which validate via the process handle.

---

## `ThreadException` (class)

Thrown on launch failures (process didn't start), misconfiguration (unset
`ManualEngine` part, missing `ext-shmop` for `ShmTransport`), or starting an
already-running `Thread`.
