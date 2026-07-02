# 5. Process Control & Lifecycle

Once a task is running you have full control over it: query its state, send
signals, wait for it, or explicitly release it. This chapter is precise about
what each method does — including the corner cases — because a pool or supervisor
depends on those exact semantics.

## Lifecycle at a glance

```
                       pause / resume / interrupt /
                        terminate / kill   (signals)
                                  │
new Thread(...) ──start()──▶  running  ──join()/reap()──▶  finished (reaped)
      │                           │                              │
   not started                 detach()                    getExitCode()
   getPid()=null            (stop tracking)                  is set here
```

Three states, from the parent's point of view:

- **not started** — constructed but `start()` not called (or it threw).
- **running** — a live child; `isAlive()` is `true`.
- **finished (reaped)** — the child exited *and* the parent collected it; its
  `proc_open` resources are freed and `getExitCode()` is set.

A fourth, deliberate off-ramp is **detached** — you stop tracking a still-running
child (see [`detach()`](#giving-up-ownership-detach)).

## State

```php
$thread->getPid();       // ?int  — PID, or null before start()
$thread->isAlive();      // bool  — is the process still running right now?
$thread->getExitCode();  // ?int  — exit code once reaped, else null
```

Exact semantics:

- **`getPid()`** returns the launched PID after `start()`, `null` before. Note in
  [detached mode](08-detached-mode.md) this is the *launcher's* ephemeral PID, not
  the real worker's.
- **`isAlive()`** reflects live process status (`proc_get_status`). It becomes
  `false` once the child exits, and also once you `detach()` (you're no longer
  tracking it), and is `false` before `start()`.
- **`getExitCode()`** is `null` until the process is **reaped** (by `join()` or a
  successful `reap()`), then holds the integer exit code. ⚠️ If you `detach()` a
  process, it is **never** reaped through this handle, so `getExitCode()` stays
  `null` forever — detaching trades the exit code for non-blocking release.

## Signals

Requires `ext-posix`. Each method sends one POSIX signal and returns `true` if it
was sent, `false` if the process isn't running (or was detached):

```php
$thread->pause();      // SIGSTOP — suspend (cannot be caught or ignored)
$thread->resume();     // SIGCONT — resume a paused process
$thread->interrupt();  // SIGINT  — Ctrl+C equivalent (catchable)
$thread->terminate();  // SIGTERM — graceful stop request (catchable)
$thread->kill();       // SIGKILL — force kill (cannot be caught)
```

Guidance:

- **`pause()` / `resume()`** are handy for throttling — freeze a worker under
  memory pressure, resume when clear. `SIGSTOP` can't be blocked, so it always
  takes effect.
- **`terminate()`** lets a well-behaved task catch `SIGTERM` (via
  `pcntl_signal`/`pcntl_async_signals` *inside* the task) and clean up. This is
  the polite way to stop work.
- **`kill()`** is the last resort — unblockable, no cleanup, possible partial
  writes. Reach for it only when `terminate()` was ignored.
- Every signal method first checks the process is alive, so calling one on a
  finished/detached thread simply returns `false` rather than erroring or hitting
  an unrelated PID.

For signalling by **raw PID** (e.g. a supervisor acting on a PID persisted
elsewhere, or a [detached worker's](08-detached-mode.md) self-reported PID), use
the [`Signal`](../src/Signal.php) helper. It detects **zombie** processes
correctly across Linux (`/proc/<pid>/status`) and macOS (`ps`), so a
not-yet-reaped dead process is reported as *not running* — see the
[API reference](11-api-reference.md#signal-final-class). Be aware of PID reuse:
only act on freshly obtained PIDs.

## Waiting: `join()`

Blocks until the process exits, then reaps it and returns the exit code:

```php
$exit = $thread->join();            // wait forever
$exit = $thread->join(timeout: 5);  // wait up to 5s; null on timeout
```

- returns the **exit code** (`0` = success) once finished, and reaps the process
  as a side effect (so `getExitCode()` is then set);
- returns **`null`** if a positive `$timeout` (in **seconds**) elapses first — the
  process is still running, and you can `join()` again or fall back to `reap()`;
- returns **`-1`** if the thread was never started.

Internally `join()` polls process status every 50 ms; `timeout: 0` (the default)
means no timeout.

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

Return value:

- **`true`** — the process is finished (or was never started / already gone) and
  has been reaped; resources are freed, `getExitCode()` is set.
- **`false`** — still running; nothing was done, call again later.

This is the primitive a **worker pool** loops over — harvesting completed workers
without stalling on the ones still running:

```php
// keep only the still-running threads each pass
$running = array_filter($running, fn(Thread $t) => !$t->reap());
```

Reaping a finished process is what prevents it from lingering as a **zombie**
(a dead process the OS keeps until its parent collects it).

## Giving up ownership: `detach()`

`detach()` stops tracking the process — it **keeps running**, but this `Thread`
no longer manages it. After detaching:

| Method | Result after `detach()` |
|---|---|
| `isAlive()` | `false` (you're not tracking it) |
| `reap()` | `true` (nothing left to reap here) |
| `getExitCode()` | `null` — **never populated** |
| `pause()`/`kill()`/… | `false` (no live handle) |

It is **non-blocking**: it closes the parent's pipe fds and drops the `proc_open`
resource **without** calling the blocking `proc_close`.

```php
$thread->detach(); // "I no longer care about this one"
```

⚠️ **`detach()` is not the same as [detached mode](08-detached-mode.md).** Use it
for short-lived fire-and-forget where the parent exits soon. A detached *live*
process, if it later exits under a **long-lived** parent, becomes a **zombie**
until that parent exits — because nothing calls `wait()` on it. For a long-lived
parent that fires and forgets, prefer `start(detached: true)`, which reparents the
worker to init so it is always reaped:

```php
// short script, don't care about result → detach is fine
$thread->detach();

// long-lived FPM/daemon parent, fire-and-forget → detached MODE
$thread->start(detached: true);
```

## Automatic cleanup (the destructor)

Every `Thread`/`ProcessHandle` has a destructor that **avoids leaking zombies**
when the object goes out of scope:

- if the child has **finished**, it is reaped (non-blocking);
- if it is **still running**, it is *detached* rather than blocking the parent on
  `proc_close`.

So a dropped `Thread` never stalls your parent. But because a still-running child
is detached (not waited on), you should still `join()`/`reap()` explicitly in
long-lived parents — or use detached mode — so cleanup is **deterministic** and
no zombie survives.

## The non-blocking guarantee

`reap()`, `detach()` and the destructor are guaranteed **non-blocking on a live
process**. The only blocking call, `proc_close`, is invoked **exclusively on an
already-dead process** (inside `join()`'s finish path and inside `reap()` when the
child has exited). `join()` itself blocks — that is its job — but nothing else
will stall your loop.

This is precisely what lets a pool poll hundreds of workers in one tight loop
without any of them holding the loop hostage. See
[10. Architecture](10-architecture.md#building-a-pool-on-launcher--processhandle)
for a complete pool example built on this guarantee.
