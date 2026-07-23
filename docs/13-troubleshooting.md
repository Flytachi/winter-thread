# 13. Troubleshooting

Symptom → cause → fix. The messages below are the **exact** strings the library
emits, so you can match what you see. Where a message is written to STDERR, it goes
to your `outputTarget` (a file, or the parent pipes when `null`; nowhere if
`/dev/null`) — so when debugging, start with `outputTarget` set to a file and
`debugMode: true`.

## Quick index

| Symptom | Likely cause | Jump to |
|---|---|---|
| `ThreadException` the moment you call `start()` | `proc_open` disabled / bad binary or runner path | [Won't start](#start-throws-threadexception) |
| Task seems to do nothing; exit code non-zero | payload rejected or not a `Runnable` | [Nothing runs](#the-task-runs-but-does-nothing) |
| `failed to deserialize payload` | secret mismatch / tampered payload | [Payload rejected](#payload-rejected) |
| Zombies pile up under a daemon/FPM | long-lived parent never reaps | [Zombies](#zombie-processes-accumulate) |
| Job stalls or vanishes silently | `null` output pipe never drained | [Broken pipe](#task-stalls-or-dies-silently-broken-pipe) |
| `Bad file descriptor` under Swoole | a bare `CliLauncher` (proc_open) bound inside a coroutine | [Swoole](#bad-file-descriptor-under-swoole) |
| `$args` missing a value you passed | `false`/`null`/non-scalar dropped | [Arguments](#an-argument-is-missing-in-args) |
| `ShmTransport requires ext-shmop` | extension not loaded | [shmop](#shmtransport-requires-ext-shmop) |
| `CliLauncher` missing binary/runner path | required arg unset in explicit construction | [CliLauncher](#clilauncher--missing-binary-or-runner-path) |
| Signals seem ignored | a handler that never exits, or wrong PID | [Signals](#signals-seem-to-do-nothing) |

## `start()` throws `ThreadException`

Messages: **"Failed to start the process using proc_open."** or **"Process failed
to start or terminated immediately."**

Causes and fixes:

- **`proc_open` is disabled.** Check `disable_functions` in `php.ini`. It must not
  list `proc_open`. Verify: `php -r "var_dump(function_exists('proc_open'));"`.
- **Wrong PHP binary** (common under **FPM**). `PHP_BINARY` under FPM is the FPM
  binary, not a CLI one. [`CliLauncher::adaptive()`](07-the-launcher.md) resolves a
  CLI binary automatically, but if it guessed wrong, set it explicitly:
  ```php
  Thread::bindLauncher(new CliLauncher(
      binaryPath: '/usr/bin/php',
      runnerPath: __DIR__ . '/vendor/flytachi/winter-thread/wRunner',
      transport:  new \Flytachi\Winter\Thread\Payload\PipeTransport(),
  ));
  ```
- **Bad/inaccessible runner path** (phar, relocated vendor, `open_basedir`). Point
  the `runnerPath:` argument at a real on-disk `wRunner`. See
  [2. Installation](02-installation-and-requirements.md#the-runner-path-wrunner).

Also: **"Thread is already running; join()/reap() it or create a new Thread before
starting again."** — you called `start()` twice on the same live `Thread`. Reap the
first run, or create a new `Thread`. See
[4. Basic Usage](04-basic-usage.md#one-start-per-thread).

## The task runs but does nothing

The process starts, but exits non-zero and your work never happened. Look at STDERR
(set an `outputTarget` file). Likely messages:

- **"Error: The provided payload is not a valid Runnable object."** — the
  serialized object isn't a `Runnable`. Ensure the class `implements Runnable`.
- **"Error: No payload received."** — the child got an empty payload. Usually a
  transport/binary mismatch or a custom launcher that didn't deliver stdin.
- **"Uncaught exception in background process: …"** followed by a stack trace — your
  `run()` threw. This is your application error; the trace tells you where. Turn on
  `debugMode: true` for warnings/notices too.

## Payload rejected

Message: **"Error: failed to deserialize payload: …"** with a non-zero exit and no
task code executed.

- **Secret mismatch.** If you sign payloads, the child verifies with the secret it
  received via `WINTER_THREAD_SECRET`. A different (or missing) secret on the child
  rejects everything. With the built-in launchers the secret is propagated for
  you; with a **custom launcher** you must forward that env var yourself. See
  [10. Security](10-security.md#how-the-secret-reaches-the-worker).
- **Non-serializable task.** A property holding a resource (PDO, socket, stream)
  can't be serialized. Move it into `run()`. See
  [4. Basic Usage](04-basic-usage.md#serializability--the-one-hard-rule).
- **Genuinely tampered payload** — the signature did its job; investigate the
  channel.

## Zombie processes accumulate

You see `<defunct>` processes multiplying under a long-lived parent (FPM worker,
daemon).

- **You never `join()`/`reap()`.** A finished child stays a zombie until the parent
  collects it. Either harvest with a [pool loop](12-patterns.md#a-bounded-worker-pool),
  or, for true fire-and-forget, use [detached mode](09-detached-mode.md):
  `start(detached: true)`.
- **Detached, but your app is PID 1 in a container.** Reparented workers land on
  PID 1, which must reap them. Add a reaping init: `docker run --init` or
  `init: true` in Compose. See
  [9. Detached Mode](09-detached-mode.md#containers-give-pid-1-a-reaper).
- Note: `detach()` (not detached *mode*) also leaves a zombie under a long-lived
  parent — that's expected. See
  [6. Process Control](06-process-control.md#giving-up-ownership-detach).

## Task stalls or dies silently (Broken pipe)

You started with `outputTarget: null` and the job hangs or disappears with no error.

- **You used `null` in true fire-and-forget — never joined, reaped, or read it.**
  `join()` and `reap()` drain the pipes internally while they wait, so a bare
  `join()` is safe at any output size. The stall only bites when *nobody* touches
  the handle: start with `null`, then never call `join()`, `reap()`, or
  `readOutput()`/`readError()`. With no drainer the ~64 KB OS buffer fills and the
  child blocks on `write()` (or gets a *Broken pipe* and dies). For fire-and-forget,
  send output to `/dev/null` (default) or a file — never `null`. See
  [5. Output & Debugging](05-output-and-debugging.md#why-devnull-is-the-default).

## `Bad file descriptor` under Swoole

`proc_open(): posix_spawn() failed: Bad file descriptor` (with a matching
`socket_free_defer close(...) failed` from Swoole) means a launch went through
`proc_open` from *inside a live coroutine*, where native `proc_open` and the
reactor contend over the process file-descriptor table.

**You should not hit this with the defaults.** `Thread::launcher()` binds
`AdaptiveLauncher`, which routes to `SwooleLauncher` (a coroutine-safe shell
background job) whenever a coroutine or runtime hooks are active. You only see this
error if you **bound a bare `CliLauncher`** and then launched from inside a
coroutine — either restore the adaptive default (`Thread::bindLauncher(...)` with
`AdaptiveLauncher::adaptive()`, or simply don't bind one) or bind `CliLauncher`
only where no coroutine runs. Plain CLI and FPM are unaffected either way. See
[7. The Launcher](07-the-launcher.md#swoolelauncher--the-coroutine-backend).

## An argument is missing in `$args`

You passed something to `start([...])` but it's absent in `run()`'s `$args`.

- **`false` and `null` are dropped by design**, and non-scalar values (arrays,
  objects) are ignored. Only `true` (→ a boolean flag) and other scalars (→
  strings) come through. Put structured data in the **constructor**, not arguments.
  See [4. Basic Usage](04-basic-usage.md#arguments).
- Remember values arrive as **strings** (`'42'`, not `42`) — cast as needed.

## `ShmTransport requires ext-shmop`

The shared-memory transport needs the `shmop` extension on **both** the parent and
the worker.

- Install/enable `ext-shmop`, or switch to `PipeTransport`/`TempFileTransport`
  (which need no extension). See [8. Payload Transports](08-payload-transports.md).

## `CliLauncher` — missing binary or runner path

If you construct `CliLauncher` explicitly, `binaryPath` and `runnerPath` are
required constructor arguments (a `null` transport is fine — it auto-detects per
launch). If you don't want to specify them, use
[`CliLauncher::adaptive()`](07-the-launcher.md), which resolves both from the
environment.

## Signals seem to do nothing

- **Your task installed a handler that never exits.** With **no** handler,
  SIGTERM/SIGINT already terminate the worker. But once you call `pcntl_signal(...)`
  you *override* that default — so if your handler only sets a flag and the loop
  never checks it (or is stuck in a long blocking call), the process appears to
  ignore the signal. Fix: actually check the flag / break long waits into chunks,
  or send `kill()` (SIGKILL, unblockable) to force it. See
  [6. Process Control](06-process-control.md#handling-signals-inside-a-task-graceful-shutdown).
- **Wrong PID in detached mode.** `start(detached: true)` returns the *launcher's*
  PID; signalling through the `Thread` won't reach the real worker. Signal the
  worker's self-reported PID via [`Signal`](14-api-reference.md#signal-final-class).
  See [9. Detached Mode](09-detached-mode.md#signal-control-still-works--via-a-self-reported-pid).
- **`ext-posix` missing.** Signal methods rely on `posix_kill`; without the
  extension they can't send signals.

## Still stuck?

Turn on maximum visibility and re-run:

```php
$thread->start(debugMode: true, outputTarget: '/tmp/wt-debug.log');
$thread->join();
echo file_get_contents('/tmp/wt-debug.log');
```

`debugMode: true` enables `E_ALL` + `display_errors` in the child, and the log file
captures both STDOUT and STDERR (including any uncaught-exception trace).
