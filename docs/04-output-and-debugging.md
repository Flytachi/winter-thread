# 4. Output & Debugging

A background process has nowhere to print by default. Winter Thread gives you
three explicit output targets and a debug switch, chosen per `start()` call.

## Output targets

The third argument to `start()` (`$outputTarget`) controls where the child's
**STDOUT and STDERR** go. Both streams always go to the **same** place:

| `$outputTarget` | Behavior | Use case |
|---|---|---|
| `'/dev/null'` *(default)* | output discarded; no pipe opened | fire-and-forget — safe by default |
| `'/path/to/file.log'` | output **appended** to the file (mode `a`) | persistent logging |
| `null` | output piped to the parent | interactive: read via `readOutput()` / `readError()` |

```php
// Discard (default)
$thread->start();

// Log to a file (created if missing, appended if present)
$thread->start(outputTarget: '/var/log/app/report.log');

// Pipe to parent and read it
$thread->start(outputTarget: null);
```

A few exact behaviors worth knowing:

- **File mode is append (`a`)**, so multiple workers can point at the same log
  without truncating each other, and a restart won't wipe history. You manage
  rotation.
- **STDOUT and STDERR share the target.** With a file, both are interleaved into
  it; with `null`, they are still two *separate* pipes you read via
  `readOutput()` and `readError()`.
- The parent process must be able to **write/create** the file path (permissions,
  `open_basedir`).

### Why `/dev/null` is the default

When output goes to a pipe (`null`) but nobody reads it, the OS pipe buffer
(typically ~64 KB) fills up and the child's next write **blocks indefinitely** —
or the child receives a *Broken pipe* and dies silently. Either way your
"fire-and-forget" job stalls or vanishes with no trace.

Defaulting to `/dev/null` means fire-and-forget jobs can **never** hit this: there
is no pipe, so there is nothing to fill. Only choose `null` when the parent
actively drains the pipe in a loop; choose a **file** when you want the output but
aren't going to read it live.

## Reading piped output

With `outputTarget: null`, the parent gets two **non-blocking** pipes. Poll them
while the process runs, then drain the tail after it exits:

```php
$thread->start(outputTarget: null);

$out = '';
while ($thread->isAlive()) {
    $out .= $thread->readOutput();   // returns whatever is buffered right now
    usleep(10_000);                  // 10 ms — don't busy-spin
}
$out .= $thread->readOutput();       // drain anything written just before exit
$thread->join();                     // reap and collect the exit code
```

Notes:

- `readOutput()` / `readError()` return **whatever is currently available** (they
  never block); an empty string means "nothing new yet", not "done".
- Read the **tail after the loop** — the worker may write right before exiting.
- These methods return `''` if you started with a file or `/dev/null` target
  (there is no pipe to read), and `''` after the handle is reaped or detached.

> **Important:** never pass `null` unless you drain the pipe in a loop like this.
> A slow or absent reader is exactly the Broken-pipe stall described above.
>
> **Under Swoole**, prefer file output over `null` — the output pipes (fd 1/2) are
> subject to the same `SWOOLE_HOOK_ALL` fd corruption as the payload pipe. The
> `AdaptiveEngine` fixes the *payload* transport automatically, but it cannot fix
> output pipes you explicitly asked for. See
> [7. Payload Transports](07-payload-transports.md#swoole--event-loop-compatibility).

## Debug mode

By default the child **suppresses all PHP diagnostics** — `error_reporting(0)`,
`display_errors` off — so a stray notice or warning can never corrupt the payload
channel or pollute your logs. This is the safe production default.

Enable full error reporting with `debugMode: true`:

```php
$thread->start(debugMode: true, outputTarget: null);

$errors = '';
while ($thread->isAlive()) {
    $errors .= $thread->readError();
    usleep(10_000);
}
$errors .= $thread->readError();
$thread->join();
```

In debug mode the child sets `error_reporting(E_ALL)` and turns on
`display_errors` / `display_startup_errors`, so notices, warnings, and startup
errors are written to STDERR (wherever your `$outputTarget` points). Use it while
developing a task; leave it **off** in production.

> Debug mode changes *diagnostics only* — it does not change how the task runs or
> how exit codes are produced.

## Uncaught exceptions are always reported

This is independent of debug mode. If your `run()` throws and doesn't catch it,
the runner catches it **for you**, writes the exception message **and full stack
trace** to STDERR, and exits with a **non-zero** code. So even with debug off and
output to `/dev/null`, you can still detect failure via the exit code:

```php
if ($thread->join() !== 0) {
    // the task failed — inspect the log file / STDERR target you configured
}
```

To capture *why* it failed, point `$outputTarget` at a file (or `null` and drain
it) so the message and trace are preserved.

## Choosing quickly

- **Production fire-and-forget** → `/dev/null` (default). Detect failure via exit
  code; if you need the reason, log to a **file**.
- **Live progress / interactive** → `null` + a drain loop.
- **Developing a task** → `debugMode: true` + a file or `null` so you see warnings
  and traces.
