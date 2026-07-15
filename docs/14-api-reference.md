# 14. API Reference

Complete reference for every public type in `Flytachi\Winter\Thread`. Signatures
match the source exactly; each entry lists parameters, return values, exceptions,
and the non-obvious semantics.

## API at a glance

The surface splits into two tiers. **Most applications only touch the Public API —
three types.** The Low-level API is for building your own pool / scheduler /
supervisor directly on the raw primitives.

### Public API — what you use

| Type | Kind | You use it to… |
|---|---|---|
| [`Runnable`](#runnable-interface) | interface | define a task — put your logic in `run()` |
| [`Thread`](#thread-final-class) | class | start & control one task as a background process |
| [`Launcher`](#launcher-interface) | interface | the parent-side backend, bound once at bootstrap |
| [`CliLauncher`](#clilauncher-final-readonly-class) | class | the default launcher — `adaptive()` or explicit |

### Low-level & extension API — what you build on

| Type | Kind | Role |
|---|---|---|
| [`Launcher`](#launcher-interface) · [`CliLauncher`](#clilauncher-final-readonly-class) | interface / class | spawn a process → return a handle |
| [`ProcessHandle`](#processhandle-final-class) | class | drive one process (reap/join/detach/signal/read) |
| [`LaunchSpec`](#launchspec-final-readonly-class) | DTO | all launch parameters in one value object |
| [`PayloadTransport`](#payloadtransport-interface) · [Pipe](#pipetransport) / [TempFile](#tempfiletransport) / [Shm](#shmtransport) | interface / class | payload delivery strategy |
| [`StagedPayload`](#stagedpayload-final-readonly-class) | DTO | the staging result a launcher consumes |
| [`Runner`](#runner-interface) · [`AdaptiveRunner`](#adaptiverunner-final-readonly-class) | interface / class | child-side execution |
| [`Signal`](#signal-final-class) | class | raw-PID POSIX helpers (zombie-aware) |
| [`ThreadException`](#threadexception-class) | class | the library's only exception |

The four **interfaces** (`Launcher`, `ProcessHandle`, `Runner`, `PayloadTransport`)
are the extension points that accept your own implementation; everything else is a
concrete type you consume.

---

## Public API

### `Runnable` (interface)

`Flytachi\Winter\Thread\Runnable` — the task contract.

```
public function run(array $args): void
```

Put your logic in `run()`; it executes in the worker process.

| Parameter | Type | Description |
|---|---|---|
| `$args` | `array<string, string\|bool>` | per-run values from `start()` — flags arrive as boolean `true`, all other values as strings |

**Returns** nothing. Signal outcome via the exit code (`return`/normal completion →
`0`; throwing → non-zero) or side effects (file/DB/queue).

**Contract:** the implementing object **must be serializable** — no live resources
(PDO, sockets, streams) in its properties; open them inside `run()`. An uncaught
exception is caught by the runner, logged to STDERR with a trace, and turned into a
non-zero exit.

---

### `Thread` (final class)

`Flytachi\Winter\Thread\Thread` — the high-level facade over one process.
**Mutable**; guards against concurrent starts.

#### `__construct`

```
new Thread(
    Runnable $runnable,
    string   $namespace = '',
    ?string  $name = null,
    ?string  $tag = null,
)
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$runnable` | `Runnable` | — | the task to execute |
| `$namespace` | `string` | `''` | logical grouping, shown in the OS process title |
| `$name` | `?string` | `null` | task name; when `null`, the Runnable's short class name (or `'anonymous'`) |
| `$tag` | `?string` | `null` | instance discriminator; shown as `@tag` (defaults to `@runnable` in the title) |

Constructing does **not** start anything. The process title is
`WinterThread <namespace> -> <name>@<tag>` (only where `cli_set_process_title()`
exists).

#### Static — launcher binding

| Method | Returns | Description |
|---|---|---|
| `Thread::bindLauncher(Launcher $launcher)` | `void` | set the process-wide launcher (call once at bootstrap) |
| `Thread::launcher()` | `Launcher` | the current launcher; lazily creates a default `CliLauncher::adaptive()` if none bound |

#### `start`

```
start(
    array   $arguments = [],
    bool    $debugMode = false,
    ?string $outputTarget = '/dev/null',
    bool    $detached = false,
): int
```

Serializes the task, launches the process, returns immediately.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$arguments` | `array<string, scalar\|null>` | `[]` | per-run args exposed in `run()`'s `$args`. `true` → flag; `false`/`null` → dropped; other scalars → strings; non-scalars ignored |
| `$debugMode` | `bool` | `false` | enable `E_ALL` + `display_errors` in the child |
| `$outputTarget` | `?string` | `'/dev/null'` | `'/dev/null'` discards; a path appends (mode `a`); `null` pipes to the parent for `readOutput()`/`readError()` |
| `$detached` | `bool` | `false` | daemonize (`fork`+`setsid`) for zombie-free fire-and-forget |

**Returns** `int` — the launched PID (in detached mode, the launcher's ephemeral
PID). **Throws** `ThreadException` if the thread is already alive, or the process
fails to start.

#### Lifecycle & waiting

| Method | Returns | Description |
|---|---|---|
| `join(int $timeout = 0)` | `?int` | block (poll 50 ms) until exit; returns the exit code, `null` on timeout (**seconds**; `0` = forever), or `-1` if never started **or** the worker was signal-killed (see [ch. 6](06-process-control.md)). Reaps on completion. |
| `reap()` | `bool` | non-blocking: `true` if finished/absent (and reaped, exit code set), `false` if still running |
| `detach()` | `void` | stop tracking (non-blocking; no `proc_close`). Afterwards `isAlive()`→`false`, `reap()`→`true`, signals→`false`, exit code never collected |

#### State

| Method | Returns | Description |
|---|---|---|
| `getPid()` | `?int` | launched PID, or `null` before `start()`; the launcher's PID in detached mode |
| `isAlive()` | `bool` | is the process running now? `false` before start / after finish / after `detach()` |
| `getExitCode()` | `?int` | exit code once reaped (`join`/`reap`); `-1` if the worker was signal-killed; `null` before reaping, and `null` forever after `detach()` |

#### Signals *(require `ext-posix`; each returns `false` if not running)*

| Method | Signal | Description |
|---|---|---|
| `pause()` | SIGSTOP | suspend (unblockable) |
| `resume()` | SIGCONT | resume a paused worker |
| `interrupt()` | SIGINT | Ctrl+C equivalent (catchable) |
| `terminate()` | SIGTERM | graceful stop request (catchable) |
| `kill()` | SIGKILL | force kill (unblockable) |

Each returns `bool` — `true` if the signal was sent. To react gracefully, the task
must install a handler; see
[6. Process Control](06-process-control.md#handling-signals-inside-a-task-graceful-shutdown).

#### Output *(non-empty only when started with `outputTarget: null`)*

| Method | Returns | Description |
|---|---|---|
| `readOutput()` | `string` | STDOUT received since the last call (consuming, non-blocking); `''` if none / not piped. Buffered by `join()`/`reap()`, so it still returns the full output *after* the process has finished |
| `readError()` | `string` | STDERR received since the last call (consuming, non-blocking); `''` if none / not piped |

#### Metadata

| Method | Returns | Description |
|---|---|---|
| `getNamespace()` | `string` | the namespace |
| `getName()` | `string` | the resolved task name |
| `getTag()` | `?string` | the tag, or `null` |

---

## Low-level & extension API

For pools, schedulers, and custom backends. `Thread` is built on exactly these
pieces — you can drive them directly.

### `Launcher` (interface)

`Flytachi\Winter\Thread\Launch\Launcher` — the parent-side backend. It spawns the
process and owns the payload-signing secret; bind one via `Thread::bindLauncher()`.

```
public function launch(LaunchSpec $spec): ProcessHandle
public function security(): ?DefaultSecurityProvider
```

| Method | Returns | Description |
|---|---|---|
| `launch(LaunchSpec $spec)` | `ProcessHandle` | spawn the process; **throws** `ThreadException` on failure |
| `security()` | `?DefaultSecurityProvider` | provider used to sign the payload, or `null` when unsigned |

Implement this to launch over SSH, in a container, or on a remote node — returning
your own `ProcessHandle` implementation.

#### `CliLauncher` (final readonly class)

`Flytachi\Winter\Thread\Launch\CliLauncher` — the default, `proc_open`-based.
Build it self-configured or explicitly:

```
CliLauncher::adaptive(
    ?string           $secret = null,      // else WINTER_THREAD_SECRET env, else no signing
    ?PayloadTransport $transport = null,   // else auto-detected per launch()
    ?string           $binaryPath = null,  // else the resolved PHP CLI binary
    ?string           $runnerPath = null,  // else the packaged wRunner
): self

new CliLauncher(
    string            $binaryPath,
    string            $runnerPath,
    ?PayloadTransport $transport = null,   // null → auto-detected per launch()
    ?string           $secret = null,      // null → no signing
)
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$binaryPath` | `string` | — | PHP CLI binary |
| `$runnerPath` | `string` | — | `wRunner` script |
| `$transport` | `?PayloadTransport` | `null` | payload staging; `null` auto-detects (`TempFile` under an active Swoole runtime, else `Pipe`) **on each `launch()`** |
| `$secret` | `?string` | `null` | signing secret; injected into the child env as `WINTER_THREAD_SECRET` |

Builds a fully `escapeshellarg`-escaped command. The signing secret (and the
ambient-secret neutralization when unsigned) is handled internally, so the child
env is derived from `$secret`. `adaptive()` resolves the binary/runner/secret from
the environment eagerly; the transport is resolved per launch (so binding during
preload picks the right transport once a worker/coroutine exists).

---

### `ProcessHandle` (interface)

`Flytachi\Winter\Thread\Launch\ProcessHandle` — the parent-side control contract a
launcher returns. Programming against it (not a concrete class) is what lets the
process backend be swapped. The default `CliLauncher` returns a **`CliProcessHandle`**
(`final class`, `proc_open`-backed, mutable — tracks process/pipes/exit-code);
custom launchers return their own implementation. Not constructed directly —
obtained from `Launcher::launch()`.

| Method | Returns | Description |
|---|---|---|
| `getPid()` | `int` | the process PID |
| `isAlive()` | `bool` | is the process running now? |
| `join(int $timeout = 0)` | `?int` | block until exit; **drains the STDOUT/STDERR pipes while waiting**; exit code, `null` on timeout (seconds), or `exitCode`/`-1` if the resource is already gone |
| `reap()` | `bool` | non-blocking; **drains the STDOUT/STDERR pipes**; `true` if finished (and reaped) |
| `detach()` | `void` | stop tracking; closes pipes, **no** `proc_close`, no transport cleanup (the child owns it) |
| `getExitCode()` | `?int` | exit code once reaped; `null` after `detach()` |
| `readOutput()` | `string` | consuming STDOUT (bytes since the last call, non-blocking); `''` if no output pipe |
| `readError()` | `string` | consuming STDERR (bytes since the last call, non-blocking); `''` if no output pipe |
| `signal(int $signal)` | `bool` | `posix_kill(pid, signal)` — only if alive; `false` otherwise |

`reap()`, `detach()` and `__destruct()` are **non-blocking on a live process**; the
blocking `proc_close` runs only on an already-dead process. `__destruct()` reaps a
finished process, or detaches a still-running one — it never blocks the parent.

---

### `LaunchSpec` (final readonly class)

`Flytachi\Winter\Thread\LaunchSpec` — an immutable bundle of every launch
parameter. Build one directly to drive a launcher without a `Thread`.

```
new LaunchSpec(
    string  $payload,
    string  $namespace = '',
    string  $name = 'anonymous',
    ?string $tag = null,
    array   $arguments = [],
    bool    $debug = false,
    ?string $output = '/dev/null',
    bool    $detached = false,
)
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$payload` | `string` | — | the already-serialized `Runnable` |
| `$namespace` | `string` | `''` | process-title grouping |
| `$name` | `string` | `'anonymous'` | process-title name |
| `$tag` | `?string` | `null` | instance tag |
| `$arguments` | `array<string, scalar\|null>` | `[]` | exposed to the task as `--arg-*` |
| `$debug` | `bool` | `false` | child-side error reporting |
| `$output` | `?string` | `'/dev/null'` | `'/dev/null'` \| file path \| `null` (pipe to parent) |
| `$detached` | `bool` | `false` | daemonize the child |

All properties are public and readonly.

---

### `PayloadTransport` (interface)

`Flytachi\Winter\Thread\Payload\PayloadTransport` — delivers the serialized payload
across the process boundary in two halves plus a cleanup.

| Method | Returns | Runs in | Description |
|---|---|---|---|
| `stage(string $payload)` | `StagedPayload` | parent | prepare delivery (fd-0 spec, CLI args, cleanup ref) |
| `receive(array $options)` | `string` | child | read the payload back (STDIN, or shm via `shmkey`) |
| `cleanup(StagedPayload $staged)` | `void` | parent | release staged resources; safe even if already gone |

`$options` in `receive()` is `array<string, mixed>` — the parsed child CLI options.

> ⚠️ The default [`AdaptiveRunner`](#adaptiverunner-final-readonly-class) receives
> from **STDIN or shm only** — it does not call a custom transport's `receive()`. A
> transport delivering out-of-band (Redis/TCP/FIFO) therefore also needs a matching
> child runner. See [8. Payload Transports](08-payload-transports.md#writing-your-own-transport).

#### `PipeTransport`

`…\Payload\PipeTransport` — payload via the child's stdin **pipe** (written after
launch). No extension. **Not** Swoole-safe (uses a pipe fd). The default in plain
CLI.

#### `TempFileTransport`

`…\Payload\TempFileTransport` — payload via a `0600` **temp file** placed on stdin,
unlinked right after launch (child keeps its fd). No extension. **Swoole-safe.**
Throws `ThreadException` if the temp file can't be created/written.

#### `ShmTransport`

`…\Payload\ShmTransport` — payload via a `0600` System V **shared-memory** segment;
the integer key is passed as `--shmkey`, and the child reads then deletes it. No
disk, **Swoole-safe.** **Requires `ext-shmop`** — throws `ThreadException`
("ShmTransport requires ext-shmop.") on both `stage()` and `receive()` if missing,
and on allocation failure.

#### `StagedPayload` (final readonly class)

`Flytachi\Winter\Thread\Payload\StagedPayload` — the result of `stage()`; the
launcher reads it generically.

```
new StagedPayload(
    array   $stdinSpec,
    array   $cliArgs = [],
    ?string $pipePayload = null,
    ?string $unlinkAfterOpen = null,
    mixed   $ref = null,
)
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$stdinSpec` | `array<int,string>` | — | `proc_open` fd-0 descriptor (`['pipe','r']` or `['file',$path,'r']`) |
| `$cliArgs` | `array<int,string>` | `[]` | extra, already-safe launch args (e.g. `['--shmkey=123']`) |
| `$pipePayload` | `?string` | `null` | written to the pipe after launch (pipe transport) |
| `$unlinkAfterOpen` | `?string` | `null` | unlinked after launch once the child holds its fd (temp-file transport) |
| `$ref` | `mixed` | `null` | opaque cleanup handle (temp path / shm key) passed to `cleanup()` |

---

### `Runner` (interface)

`Flytachi\Winter\Thread\Runner\Runner` — child-side execution strategy.

```
public function execute(array $options): int
```

| Parameter | Type | Description |
|---|---|---|
| `$options` | `array<string, mixed>` | parsed `wRunner` CLI options (`namespace`, `name`, `tag`, `debug`, `detach`, `shmkey`) |

**Returns** `int` — the process exit code (`0` success, non-zero failure).

#### `AdaptiveRunner` (final readonly class)

`Flytachi\Winter\Thread\Runner\AdaptiveRunner` — the default child-side runner
(driven by `wRunner`). It depends only on a security provider — **not** on the
`Launcher` — so the two sides stay independent.

```
new AdaptiveRunner(?DefaultSecurityProvider $security = null, mixed $errStream = null)
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$security` | `?DefaultSecurityProvider` | `null` | verifies the payload signature; build it from the same secret the parent signed with (`null` = unsigned). `wRunner` builds it from `WINTER_THREAD_SECRET` |
| `$errStream` | `resource\|null` | `null` | where diagnostics are written; defaults to `STDERR` (injectable for tests) |

`execute()` flow: receive payload (`--shmkey` → shm, else STDIN) → verify +
deserialize via `opis/closure` (rejecting empty, non-`Runnable`, or
unsigned/tampered payloads with a non-zero exit) → optional `fork`+`setsid`
(detached) → set process title → `run()` → exit code.

> To use a **custom** `Runner`, you replace the child bootstrap: write your own
> script like `wRunner` that constructs your runner (typically launched by a
> [custom `Launcher`](#launcher-interface)). There is no binding seam for the
> runner — by design, the child side is independent of the parent's `Launcher`.

---

### `Signal` (final class)

`Flytachi\Winter\Thread\Signal` — static POSIX helpers on a raw PID.
`isProcessRunning()` treats zombies (state `Z`) as **not** running, on both Linux
(`/proc/<pid>/status`) and macOS (`ps`).

| Method | Returns | Description |
|---|---|---|
| `isProcessRunning(int $pid)` | `bool` | live (non-zombie) process with this PID exists? |
| `interrupt(int $pid)` | `bool` | send SIGINT (if running) |
| `termination(int $pid)` | `bool` | send SIGTERM (if running) |
| `close(int $pid)` | `bool` | send SIGHUP (if running) |
| `kill(int $pid)` | `bool` | send SIGKILL (if running) |
| `wait(int $pid, int $timeout = 10)` | `bool` | poll until gone; `false` on timeout (seconds) |
| `interruptAndWait(int $pid, int $timeout = 10)` | `bool` | SIGINT then wait |
| `terminationAndWait(int $pid, int $timeout = 10)` | `bool` | SIGTERM then wait |
| `closeAndWait(int $pid, int $timeout = 10)` | `bool` | SIGHUP then wait |

> **No `pause`/`resume` here.** `Signal` covers only stop/terminate signals.
> Suspend/resume by raw PID is not exposed — use `posix_kill($pid, SIGSTOP)` /
> `posix_kill($pid, SIGCONT)` directly, or the
> [`Thread::pause()`/`resume()`](#thread-final-class) API.

> ⚠️ Raw PIDs are subject to OS **PID reuse**. Prefer a `Thread`/`ProcessHandle` for
> reliable tracking; use `Signal` only with freshly obtained PIDs (e.g. a
> [detached worker's](09-detached-mode.md) self-reported PID).

---

### `ThreadException` (class)

`Flytachi\Winter\Thread\ThreadException extends \RuntimeException` — the library's
only exception type.

Thrown on: launch failure (`proc_open` denied, or the process died immediately),
starting an already-running `Thread`, misconfiguration (`ShmTransport` without
`ext-shmop`), and transport staging failures (temp file / shm allocation). Catch it
around `start()` and launcher setup.
