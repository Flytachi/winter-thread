# 4. Output & Debugging

A background process has nowhere to print by default. Winter Thread gives you
three explicit output targets and a debug switch.

## Output targets

The third argument to `start()` (`$outputTarget`) controls where the child's
STDOUT and STDERR go:

| `$outputTarget` | Behavior | Use case |
|---|---|---|
| `'/dev/null'` *(default)* | output discarded; no pipe opened | fire-and-forget — safe by default |
| `'/path/to/file.log'` | output appended to the file | persistent logging |
| `null` | output piped to the parent | interactive: read via `readOutput()` / `readError()` |

```php
// Discard (default)
$thread->start();

// Log to a file
$thread->start(outputTarget: '/var/log/app/report.log');

// Pipe to parent and read it
$thread->start(outputTarget: null);
```

### Why `/dev/null` is the default

When output goes to a pipe (`null`) but nobody reads it, the OS pipe buffer
(~64 KB) fills up and the next write **blocks** — or the child receives a *Broken
pipe* and dies silently. Defaulting to `/dev/null` means fire-and-forget jobs can
never hit this. Only choose `null` when the parent actively drains the pipe.

## Reading piped output

With `outputTarget: null`, poll `readOutput()` / `readError()` while the process
runs (the pipes are non-blocking):

```php
$thread->start(outputTarget: null);

$out = '';
while ($thread->isAlive()) {
    $out .= $thread->readOutput();
    usleep(10_000);
}
$out .= $thread->readOutput(); // drain the tail
$thread->join();
```

> **Important:** never pass `null` unless you drain the pipe in a loop like this.
> Under Swoole, prefer file output over `null` — the output pipes are subject to
> the same `SWOOLE_HOOK_ALL` fd corruption as the payload pipe.

## Debug mode

By default the child suppresses PHP notices/warnings so stray output can't
corrupt the payload channel or your logs. Enable full error reporting with
`debugMode: true`:

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

In debug mode `error_reporting(E_ALL)` and `display_errors` are on inside the
child, so warnings and the full stack trace of an uncaught exception are written
to STDERR.

## Uncaught exceptions

If your `run()` throws and doesn't catch it, the runner catches it for you,
writes the message and stack trace to STDERR, and exits with a **non-zero** code.
So even without debug mode you can detect failure via `join()`:

```php
if ($thread->join() !== 0) {
    // the task failed — inspect the log file / STDERR you configured
}
```
