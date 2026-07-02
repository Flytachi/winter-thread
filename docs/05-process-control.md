# 5. Process Control & Lifecycle

Once a task is running you have full control over it: query its state, send
signals, wait for it, or explicitly release it.

## Lifecycle at a glance

```
new Thread(...)  ──start()──▶  running  ──join()/reap()──▶  finished (reaped)
                                  │
                       pause/resume/interrupt/
                        terminate/kill/detach
```

## State

```php
$thread->getPid();       // ?int  — PID, or null before start()
$thread->isAlive();      // bool  — is the process still running?
$thread->getExitCode();  // ?int  — exit code once reaped, else null
```

## Signals

Requires `ext-posix`. Each returns `true` if the signal was sent, `false` if the
process isn't running.

```php
$thread->pause();      // SIGSTOP — suspend (cannot be ignored)
$thread->resume();     // SIGCONT — resume a paused process
$thread->interrupt();  // SIGINT  — Ctrl+C equivalent (catchable)
$thread->terminate();  // SIGTERM — graceful stop request (catchable)
$thread->kill();       // SIGKILL — force kill (cannot be caught)
```

`pause()`/`resume()` are handy for throttling; `terminate()` lets a well-behaved
task clean up; `kill()` is the last resort.

For signalling by raw PID (e.g. a supervisor acting on a PID stored elsewhere),
see the [`Signal`](../src/Signal.php) helper, which additionally detects zombie
processes correctly across Linux and macOS.

## Waiting: `join()`

Blocks until the process exits, then reaps it and returns the exit code:

```php
$exit = $thread->join();          // wait forever
$exit = $thread->join(timeout: 5); // wait up to 5s; null on timeout
```

- returns the **exit code** (`0` = success) once finished;
- returns `null` if a positive `$timeout` elapses first;
- returns `-1` if the thread was never started.

## Reaping without blocking: `reap()`

`reap()` is the non-blocking counterpart of `join()`. It collects the process
**only if it has already finished**, and returns immediately otherwise:

```php
if ($thread->reap()) {
    // finished and cleaned up; getExitCode() is now set
} else {
    // still running — do something else and check again later
}
```

This is the primitive a **worker pool** loops over — harvesting completed workers
without stalling on the ones still running:

```php
$running = array_filter($running, fn(Thread $t) => !$t->reap());
```

Reaping a finished process is what prevents it from lingering as a *zombie*
(a dead process the OS keeps until its parent collects it).

## Giving up ownership: `detach()`

`detach()` stops tracking the process — it keeps running, but this `Thread` no
longer manages it (`isAlive()` → `false`, `reap()` → `true`, signals → `false`).
It is **non-blocking** (it never calls the blocking `proc_close`).

```php
$thread->detach(); // "I no longer care about this one"
```

Use it for short-lived fire-and-forget where you don't want to track the result.
Note: a *detached* live process, if it later exits under a long-lived parent,
becomes a zombie until the parent exits — for a long-lived parent prefer
[detached **mode**](08-detached-mode.md) (`start(detached: true)`), which
reparents the worker to init.

## Automatic cleanup

Every `Thread` has a destructor that avoids leaking zombies: when the object goes
out of scope, a finished process is reaped (non-blocking); a still-running one is
detached rather than blocking the parent. You should still `join()`/`reap()`
explicitly in long-lived parents so cleanup is deterministic.

## The non-blocking guarantee

`reap()`, `detach()` and the destructor are guaranteed **non-blocking on a live
process**. `join()` blocks (that's its job); `proc_close` — which does block —
is only ever called on an already-dead process. This is what lets a pool poll
hundreds of workers in one tight loop without stalling.
