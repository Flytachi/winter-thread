# 11. API Reference

Namespace root: `Flytachi\Winter\Thread`. Every signature below matches the source
exactly; the notes call out the non-obvious semantics.

---

## `Runnable` (interface)

```
namespace Flytachi\Winter\Thread;

interface Runnable
{
    public function run(array $args): void;
}
```

The task contract. Implement it and put your logic in `run()`, which executes in
the worker process. `$args` is `array<string, string|bool>` — per-run values from
`start()`, where flags arrive as boolean `true` and everything else as a string.
Return value is ignored; signal outcome via exit code (return → `0`, throw →
non-zero) or side effects. The object **must be serializable** — no live resources
in properties (open them inside `run()`).

---

## `Thread` (final class)

The facade. Constructing it does not start anything. It is **mutable** (tracks one
process handle) and guards against concurrent starts.

### Construction

```
new Thread(
    Runnable $runnable,
    string   $namespace = '',
    ?string  $name = null,   // null → short class name, or 'anonymous' for an anonymous class
    ?string  $tag = null,
)
```

### Static engine binding

```
Thread::bindEngine(Engine $engine): void   // set the process-wide engine
Thread::engine(): Engine                   // current engine (lazily an AdaptiveEngine)
```

### Lifecycle

```
start(
    array   $arguments = [],
    bool    $debugMode = false,
    ?string $outputTarget = '/dev/null',
    bool    $detached = false,
): int
```
Serializes the task, launches the process, returns its **PID**. Throws
`ThreadException` if the thread is **already alive**, or if the process fails to
start. `$arguments` are `array<string, scalar|null>`: `true` → flag, `false`/`null`
→ dropped, other scalars stringified; non-scalar values are ignored.

```
join(int $timeout = 0): ?int
```
Blocks until the child exits (polling every 50 ms), reaps it, returns the exit
code. `$timeout` is in **seconds** (`0` = forever). Returns `null` on timeout, and
`-1` if the thread was never started.

```
reap(): bool
```
Non-blocking. Returns `true` if the child has finished/absent (and reaps it,
setting the exit code), `false` if still running.

```
detach(): void
```
Stops tracking the child (non-blocking; no `proc_close`). Afterwards `isAlive()` →
`false`, `reap()` → `true`, signals → `false`, and the exit code is **never**
collected. See [ProcessHandle](#processhandle-final-class).

### State

```
getPid(): ?int        // launched PID, or null before start(); launcher's PID in detached mode
isAlive(): bool       // is the process running now? false before start / after finish / after detach
getExitCode(): ?int   // set once reaped (join/reap); null otherwise, and null forever after detach()
```

### Signals *(require ext-posix; each returns false if not running)*

```
pause(): bool       // SIGSTOP
resume(): bool      // SIGCONT
interrupt(): bool   // SIGINT
terminate(): bool   // SIGTERM
kill(): bool        // SIGKILL
```

### Output *(non-empty only when started with outputTarget: null)*

```
readOutput(): string   // STDOUT available right now (non-blocking); '' if none / not piped
readError(): string    // STDERR available right now (non-blocking); '' if none / not piped
```

### Metadata

```
getNamespace(): string
getName(): string
getTag(): ?string
```

---

## `Engine` (interface)

```
namespace Flytachi\Winter\Thread\Engine;

transport(): PayloadTransport
launcher(): Launcher
runner(): Runner
binaryPath(): string
runnerPath(): string
security(): ?DefaultSecurityProvider   // Opis\Closure\Security\DefaultSecurityProvider, or null
```

Used **parent-side**. The child always builds its own `AdaptiveEngine` (see
[10. Architecture](10-architecture.md)); the parent's `security()` result is
propagated to it via the `WINTER_THREAD_SECRET` env var.

### `AdaptiveEngine` (final readonly class, default)

```
new AdaptiveEngine(
    ?string           $secret = null,     // else WINTER_THREAD_SECRET env, else null
    ?PayloadTransport $transport = null,  // else auto: TempFile under an active Swoole runtime, else Pipe
    ?string           $binaryPath = null, // else resolved PHP CLI binary (CLI: PHP_BINARY; FPM: PHP_BINDIR/php)
    ?string           $runnerPath = null, // else the packaged wRunner
    ?Launcher         $launcher = null,   // else default CliLauncher built from the above
)
```
Immutable. To change one aspect, construct a new instance.

### `ManualEngine` (final class)

Immutable withers (each returns a clone); an unset required part throws
`ThreadException` when accessed.

```
withTransport(PayloadTransport $t): static
withBinaryPath(string $path): static
withRunnerPath(string $path): static
withSecurity(string $secret): static
withLauncher(Launcher $l): static
```
With a custom `withLauncher(...)`, `binaryPath`/`runnerPath`/`transport` need not
be set (the launcher owns its wiring); otherwise all three are required.

---

## `Launcher` (interface)

```
namespace Flytachi\Winter\Thread\Launch;

launch(LaunchSpec $spec): ProcessHandle   // throws ThreadException on failure
```

### `CliLauncher` (final readonly class)

```
new CliLauncher(
    string           $binaryPath,
    string           $runnerPath,
    PayloadTransport $transport,
    array            $childEnv = [],  // e.g. ['WINTER_THREAD_SECRET' => '…']; merged over inherited env
)
```
Spawns via `proc_open` using a fully `escapeshellarg`-escaped command. When
`$childEnv` is empty the child **inherits** the parent environment; when non-empty
it is merged **over** the inherited environment.

---

## `ProcessHandle` (final class)

The low-level process primitive returned by a launcher; drive it directly from a
pool. **Mutable** — it tracks the process/pipes/exit-code state.

```
getPid(): int
isAlive(): bool
join(int $timeout = 0): ?int   // seconds; exit code, null on timeout, or exitCode/-1 if the resource is gone
reap(): bool                   // non-blocking; true if finished (and reaped)
detach(): void                 // stop tracking; closes pipes, no proc_close, no cleanup (child owns it)
getExitCode(): ?int            // set once reaped; null after detach()
readOutput(): string           // non-blocking; '' if no output pipe
readError(): string            // non-blocking; '' if no output pipe
signal(int $signal): bool      // posix_kill(pid, signal) — only if alive; false otherwise
```

`reap()`, `detach()` and `__destruct()` are **non-blocking on a live process**; the
blocking `proc_close` runs only on an already-dead process. `__destruct()` reaps a
finished process, or detaches a still-running one (never blocks the parent).

---

## `LaunchSpec` (final readonly class)

```
namespace Flytachi\Winter\Thread;

new LaunchSpec(
    string  $payload,               // the already-serialized Runnable
    string  $namespace = '',
    string  $name = 'anonymous',
    ?string $tag = null,
    array   $arguments = [],         // array<string, scalar|null>, exposed to the task as --arg-*
    bool    $debug = false,
    ?string $output = '/dev/null',   // '/dev/null' | file path | null (pipe to parent)
    bool    $detached = false,
)
```
Immutable bundle of everything `Launcher::launch()` needs. Build one directly when
driving a launcher without a `Thread`.

---

## `PayloadTransport` (interface)

```
namespace Flytachi\Winter\Thread\Payload;

stage(string $payload): StagedPayload   // parent: prepare delivery
receive(array $options): string         // child: read the payload back (STDIN, or shm via 'shmkey')
cleanup(StagedPayload $staged): void    // parent: release staged resources (safe if already gone)
```

Implementations:

- **`PipeTransport`** — stdin pipe; no extension; not Swoole-safe.
- **`TempFileTransport`** — `0600` temp file on stdin, unlinked right after launch;
  no extension; Swoole-safe.
- **`ShmTransport`** — System V shared memory via `--shmkey`; requires `ext-shmop`
  (throws `ThreadException` if missing, on both `stage()` and `receive()`);
  Swoole-safe.

### `StagedPayload` (final readonly class)

```
new StagedPayload(
    array   $stdinSpec,              // proc_open fd-0 descriptor (e.g. ['pipe','r'] or ['file',$path,'r'])
    array   $cliArgs = [],           // extra, already-safe launch args (e.g. ['--shmkey=123'])
    ?string $pipePayload = null,     // written to the pipe after launch (pipe transport)
    ?string $unlinkAfterOpen = null, // unlinked after launch once the child holds its fd (temp-file transport)
    mixed   $ref = null,             // opaque cleanup handle (temp path / shm key) for cleanup()
)
```

---

## `Runner` (interface)

```
namespace Flytachi\Winter\Thread\Runner;

execute(array $options): int   // runs in the child; returns the exit code
```

`$options` are the parsed `wRunner` CLI options (`namespace`, `name`, `tag`,
`debug`, `detach`, `shmkey`).

### `ProcessRunner` (final readonly class)

```
new ProcessRunner(Engine $engine, mixed $errStream = null)
```
The default runner (driven by `wRunner`). `execute()`: receive payload → verify +
deserialize via `opis/closure` (rejecting empty, non-`Runnable`, or
unsigned/tampered payloads with a non-zero exit) → optional `fork`+`setsid`
(detached) → set process title → `run()`. `$errStream` overrides where diagnostics
are written (defaults to `STDERR`; injectable for tests).

---

## `Signal` (final class)

Static POSIX helpers that operate on a raw PID. `isProcessRunning()` treats zombie
processes (state `Z`) as **not** running, on both Linux (`/proc/<pid>/status`) and
macOS (`ps`).

```
namespace Flytachi\Winter\Thread;

Signal::isProcessRunning(int $pid): bool
Signal::interrupt(int $pid): bool      // SIGINT
Signal::termination(int $pid): bool    // SIGTERM
Signal::close(int $pid): bool          // SIGHUP
Signal::kill(int $pid): bool           // SIGKILL
Signal::wait(int $pid, int $timeout = 10): bool                 // poll until gone; false on timeout (seconds)
Signal::interruptAndWait(int $pid, int $timeout = 10): bool
Signal::terminationAndWait(int $pid, int $timeout = 10): bool
Signal::closeAndWait(int $pid, int $timeout = 10): bool
```

> ⚠️ Raw PIDs are subject to PID reuse by the OS. For reliable tracking prefer a
> `Thread`/`ProcessHandle`, which validate via the process handle. Use `Signal`
> only with freshly obtained PIDs (e.g. a [detached worker's](08-detached-mode.md)
> self-reported PID).

---

## `ThreadException` (class)

```
namespace Flytachi\Winter\Thread;

class ThreadException extends \RuntimeException {}
```

The library's only exception type. Thrown on: launch failure (`proc_open` denied,
or the process died immediately), starting an already-running `Thread`,
misconfiguration (an unset `ManualEngine` part, or `ShmTransport` without
`ext-shmop`), and transport staging failures (temp file / shm allocation).
