# 11. Architecture & Internals

This chapter is for people building *on top of* the engine (pools, schedulers) or
who simply want to know how it works. `Thread` is a thin facade; the real work is
done by a handful of small, single-purpose components split across two processes.

## The two-process model

Everything happens in exactly two processes, and they never share objects — only
bytes on a channel and flags on a command line:

- **Parent** (your app) — serializes the task, stages the payload, and `proc_open`s
  the worker. Uses the `Launcher` you bound.
- **Child** (`bin: wRunner`) — a clean PHP CLI process that constructs an
  **`AdaptiveRunner`**, reads `WINTER_THREAD_SECRET` from its environment to build
  the signature verifier, receives the payload, verifies + deserializes it,
  optionally daemonizes, and runs the task.

The two sides are **fully independent**: the parent-side `Launcher` and the
child-side `Runner` don't reference each other, and neither is shipped across the
boundary. Only three things cross it:

- the **payload**, over the chosen transport channel;
- the **secret**, via the `WINTER_THREAD_SECRET` env var (owner-only), read
  directly by `wRunner` (see [10. Security](10-security.md));
- a few **CLI flags** (`--namespace`, `--shmkey`, `--detach`, `--arg-*`), from
  which the child also picks its receiving transport (`--shmkey` → shm, else STDIN
  — see [8. Payload Transports](08-payload-transports.md)).

## Component map

```
════════════ PARENT ════════════          ═══════ CHILD (bin: wRunner) ═══════

          Thread  (facade)                    wRunner (thin bootstrap script)
   start / join / reap / detach / …              │ AdaptiveRunner::adaptive()
                 │ asks the Launcher              │   reads WINTER_THREAD_SECRET (env)
                 ▼                                ▼
   Launcher (interface) ◀── bindLauncher()    Runner (interface)
     └ CliLauncher (default, proc_open)         └ AdaptiveRunner (child-side default)
          │ launch(): ProcessHandle                1. receive()  (shmkey? shm : STDIN)
          │ security(): ?provider                  2. Opis unserialize + verify signature
          │ (holds transport, binary/runner,       3. detached? fork + setsid
          │  secret — its own concern)             4. set process title
          ▼                                        5. runnable->run(args)
   ProcessHandle (interface) ◀═ a Pool drives it   6. exit(code)
     └ CliProcessHandle (proc_open)
       pid, isAlive, reap, join,             PayloadTransport (interface, parent-only)
       readOutput/Error, signal, detach        ├ PipeTransport    (stage / cleanup)
                                                ├ TempFileTransport
                                                └ ShmTransport

   (Launcher and Runner are independent — connected only by payload + env + flags)
```

## Responsibilities

| Type | Kind | Mutability | Responsibility |
|---|---|---|---|
| `Runnable` | interface | — | the task contract (`run(array $args)`) |
| `Thread` | facade | mutable | the friendly Java-like API; delegates to the launcher |
| `Launcher` | interface | — | parent side: spawn a process → `ProcessHandle`; sign payload (`security()`) |
| `CliLauncher` | class | `readonly` | default `proc_open` launcher; self-configures via `adaptive()`; holds transport/paths/secret |
| `ProcessHandle` | interface | — | parent-side control contract (reap/join/detach/signals/read) |
| `CliProcessHandle` | class | mutable (tracks state) | the `proc_open`-backed `ProcessHandle` |
| `PayloadTransport` | interface | — | parent-side payload staging (`stage`/`cleanup`) |
| `Pipe`/`TempFile`/`Shm` `Transport` | class | — | the three delivery strategies |
| `Runner` | interface | — | child-side: read payload, deserialize, run (independent of `Launcher`) |
| `AdaptiveRunner` | class | `readonly` | default child-side runner (+ detached fork/setsid) |
| `LaunchSpec` | DTO | `readonly` | all launch parameters in one value object |
| `StagedPayload` | DTO | `readonly` | staging result (fd-0 spec, cli args, cleanup ref) |
| `Signal` | class | static | POSIX signal helpers, with zombie detection |
| `ThreadException` | class | — | the library's only exception (`extends RuntimeException`) |

Interfaces exist **only** at genuine extension points (`Launcher`, `ProcessHandle`,
`Runner`, `PayloadTransport`). The DTOs (`LaunchSpec`, `StagedPayload`) are concrete
types you *consume*, not implement.

## A launch, step by step

1. **`Thread::start()`** guards against a double-start, serializes the `Runnable`
   with `opis/closure` (signed if a secret is set), and builds a `LaunchSpec` with
   the namespace/name/tag, arguments, debug flag, output target, and detached flag.
2. **`Launcher::launch($spec)`** asks the transport to `stage()` the
   payload, assembles the descriptor set (fd 0 from the staged `stdinSpec`; fd 1/2
   to the output file, or to non-blocking pipes when `output === null`), builds a
   fully `escapeshellarg`-escaped command
   (`php wRunner --namespace=… --name=… [--tag=…] [--debug] [--detach] [--shmkey=…] [--arg-…]`),
   sets the child env (adding `WINTER_THREAD_SECRET` when signing), and `proc_open`s
   it.
3. The launcher then writes the pipe payload (pipe transport) or unlinks the temp
   file (temp-file transport), sets the output pipes non-blocking (when
   `output === null`), **verifies the process actually started**, and returns a
   `ProcessHandle`. If anything failed it cleans up the staged resource and throws
   `ThreadException`.
4. In the **child**, `wRunner` sets `set_time_limit(0)`, `ignore_user_abort(true)`,
   toggles error reporting from the `--debug` flag, builds a verifier from
   `WINTER_THREAD_SECRET`, and runs an `AdaptiveRunner`: `receive` → verify +
   deserialize → (if `--detach`) `fork`+`setsid` → set process title →
   `runnable->run(parsedArgs)` → `exit(code)`.

## Building a pool on `Launcher` + `ProcessHandle`

The framework-facing primitive is the launcher and its handle — no `Thread` object
per task. Build a `LaunchSpec` (serialize the task yourself, or reuse one spec
across launches), fan out, and harvest with a single non-blocking loop:

```php
use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Launch\ProcessHandle;
use Flytachi\Winter\Thread\LaunchSpec;

$launcher = CliLauncher::adaptive();

// launch a batch (payload = the already-serialized Runnable bytes)
$handles = array_map(
    fn($job) => $launcher->launch(new LaunchSpec(
        payload:   $job->serialized(),
        namespace: 'Pool',
        name:      $job->name(),
    )),
    $jobs
);

// one non-blocking harvest loop; no zombies, never stalls on a live worker
while ($handles !== []) {
    foreach ($handles as $i => $h) {
        if ($h->reap()) {                 // finished → collected
            $exit = $h->getExitCode();
            unset($handles[$i]);
        }
    }
    usleep(20_000);                       // 20 ms between passes
}
```

Because `reap()`/`detach()` are **non-blocking on live processes** (see
[6. Process Control](06-process-control.md#the-non-blocking-guarantee)), a single
loop can manage hundreds of workers efficiently: `proc_close` — the one blocking
call — only ever runs on an already-dead process. To bound concurrency, launch in
waves (keep at most *N* handles in flight and only launch more as slots free).
Signal-based control uses each worker's self-reported PID, so the model works
identically for attached and detached workers.

## Serializing a task outside `Thread`

`Thread` serializes for you, but a pool that owns its own `LaunchSpec`s can do it
directly with the bound launcher's security provider:

```php
$payload = \Opis\Closure\serialize($runnable, Thread::launcher()->security());
$spec    = new LaunchSpec(payload: $payload, name: 'MyTask');
```

The child verifies this exactly as it would a `Thread`-produced payload — the two
paths are byte-compatible.
