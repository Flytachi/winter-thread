# 10. Architecture & Internals

This chapter is for people building *on top of* the engine (pools, schedulers) or
who simply want to know how it works. `Thread` is a thin facade; the real work is
done by a handful of small, single-purpose components.

## Component map

```
════════════ PARENT ════════════          ═══════ CHILD (bin: wRunner) ═══════

          Thread  (facade)                       wRunner (thin bootstrap)
   start / join / reap / detach / …                     │ builds an Engine
                 │ asks the Engine                       ▼
        ┌──────  Engine  ◀── bindEngine() ──▶  Engine ───┤   (same engine type)
        │   AdaptiveEngine (default)                      ▼
        │   ManualEngine                              Runner (interface)
        │                                              └ ProcessRunner
        │   Engine provides:                             1. transport.receive()
        │    • transport(): PayloadTransport             2. Opis unserialize (verify)
        │    • launcher():  Launcher                     3. detached? fork + setsid
        │    • runner():    Runner                       4. set process title
        │    • binaryPath() / runnerPath()               5. runnable->run(args)
        │    • security()                                6. exit(code)
        ▼
   Launcher (interface)              PayloadTransport (interface)
     └ CliLauncher (proc_open)         ├ PipeTransport
          │ launch(LaunchSpec)         ├ TempFileTransport
          ▼                            └ ShmTransport
    ProcessHandle  ◀════════ a Pool drives THIS directly
      pid, isAlive, reap, join,
      readOutput/Error, signal, detach
```

## Responsibilities

| Type | Kind | Responsibility |
|---|---|---|
| `Runnable` | interface | the task contract (`run(array $args)`) |
| `Thread` | facade | the friendly Java-like API; delegates to the engine |
| `Engine` | interface | selects/holds transport, launcher, runner, paths, secret |
| `AdaptiveEngine` / `ManualEngine` | class | self-configuring / explicit engines |
| `Launcher` | interface | parent side: spawn a process → `ProcessHandle` |
| `CliLauncher` | class | `proc_open`-based launcher |
| `ProcessHandle` | class | low-level process state (reap/join/detach/signals) |
| `PayloadTransport` | interface | parent↔child payload delivery |
| `Pipe`/`TempFile`/`Shm` `Transport` | class | the three delivery strategies |
| `Runner` | interface | child side: read payload, deserialize, run |
| `ProcessRunner` | class | default runner (+ detached fork/setsid) |
| `LaunchSpec` | DTO | all launch parameters in one value object |
| `StagedPayload` | DTO | staging result (fd-0 spec, cli args, cleanup ref) |
| `Signal` | class | POSIX signal helpers, with zombie detection |

Interfaces exist only at genuine extension points (`Engine`, `Launcher`,
`Runner`, `PayloadTransport`); `ProcessHandle` and the DTOs are concrete.

## A launch, step by step

1. `Thread::start()` serializes the `Runnable` (signed if a secret is set) and
   builds a `LaunchSpec`.
2. `Engine::launcher()->launch(spec)` asks the transport to `stage()` the payload,
   assembles an escaped command
   (`php wRunner --namespace=… [--shmkey=…] [--detach]`), and `proc_open`s it.
3. The launcher writes the pipe payload / unlinks the temp file, verifies the
   process started, and returns a `ProcessHandle`.
4. In the child, `wRunner` builds an `Engine`, and its `ProcessRunner` receives
   the payload, deserializes+verifies it, optionally daemonizes, and runs the task.

## Building a pool on `Launcher` + `ProcessHandle`

The framework-facing primitive is the launcher and its handle — no `Thread`
needed per task:

```php
use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Launch\ProcessHandle;
use Flytachi\Winter\Thread\LaunchSpec;

$launcher = (new AdaptiveEngine())->launcher();

// launch a batch
$handles = array_map(
    fn($job) => $launcher->launch(new LaunchSpec(payload: $job->serialized())),
    $jobs
);

// one non-blocking harvest loop; no zombies, never stalls on a live worker
while ($handles !== []) {
    $handles = array_filter($handles, fn(ProcessHandle $h) => !$h->reap());
    usleep(20_000);
}
```

Because `reap()`/`detach()` are non-blocking on live processes (see
[5. Process Control](05-process-control.md)), a single loop can manage hundreds
of workers efficiently. Signal-based control uses each worker's self-reported PID,
so the model works identically for attached and detached workers.
