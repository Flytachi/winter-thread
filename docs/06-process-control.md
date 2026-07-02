# 6. Process Control & Lifecycle

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
  [detached mode](09-detached-mode.md) this is the *launcher's* ephemeral PID, not
  the real worker's.
- **`isAlive()`** reflects live process status (`proc_get_status`). It becomes
  `false` once the child exits, and also once you `detach()` (you're no longer
  tracking it), and is `false` before `start()`. A **paused** worker (SIGSTOP via
  `pause()`) is still `true` — it is suspended, not gone.
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
elsewhere, or a [detached worker's](09-detached-mode.md) self-reported PID), use
the [`Signal`](../src/Signal.php) helper. It detects **zombie** processes
correctly across Linux (`/proc/<pid>/status`) and macOS (`ps`), so a
not-yet-reaped dead process is reported as *not running* — see the
[API reference](14-api-reference.md#signal-final-class). Be aware of PID reuse:
only act on freshly obtained PIDs.

### Handling signals inside a task (graceful shutdown)

By default SIGTERM, SIGINT, and SIGHUP already **terminate** the worker — so
`terminate()`, `interrupt()`, and `kill()` stop a task out of the box,
with **no signal code in `run()` at all** (the [test suite](15-testing.md) verifies
this against a plain `sleep()` task). You only add a handler when you want a
**graceful** stop: catch the signal, flush/checkpoint/release, and exit cleanly
instead of being killed abruptly mid-work. It is optional, lives **inside your
task**, and needs `ext-pcntl`:

```php
final class ImportRows implements Runnable
{
    public function run(array $args): void
    {
        $stop = false;

        // Deliver pending signals between PHP statements (no manual pcntl_signal_dispatch()).
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
        pcntl_signal(SIGINT,  function () use (&$stop) { $stop = true; });

        foreach ($this->rows() as $row) {
            if ($stop) {
                $this->checkpoint();      // flush progress, release locks…
                return;                   // clean exit → exit code 0
            }
            $this->process($row);
        }
    }
}
```

Now the parent can ask for a graceful stop and confirm it landed:

```php
$thread->terminate();          // SIGTERM → the handler sets $stop = true
$exit = $thread->join(5);      // give it up to 5s to checkpoint and exit
if ($exit === null) {
    $thread->kill();           // it ignored us / is stuck → force it
    $thread->join();
}
```

Key points:

- **Without a handler the worker still stops** — the signal's default action
  terminates it, just *abruptly*: no cleanup, and the exit is signal-based (a
  non-zero code), not `0`. A handler only changes *how* it stops, not *whether*.
- **`pcntl_async_signals(true)`** is the modern way — handlers fire between
  statements without you calling `pcntl_signal_dispatch()` in the loop. It requires
  `ext-pcntl` (already a dependency).
- **SIGKILL (`kill()`) and SIGSTOP (`pause()`) cannot be handled** — no cleanup is
  possible. Design cleanup around SIGTERM; keep SIGKILL as the last resort.
- **Blocking C calls** (a long `sleep()`, a synchronous DB query) only see the
  signal when they return. For tight responsiveness, break long waits into short
  chunks and re-check your stop flag.
- A handler that finishes `run()` normally yields **exit code 0**; throw from it if
  you want the run recorded as failed.

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
means no timeout. The timeout is in **whole seconds** — there is no sub-second
granularity. If you need finer control, drive `reap()` in your own loop with a
shorter `usleep()`.

### `-1` is overloaded — read it carefully

A worker **killed by a signal** (SIGTERM/SIGINT/SIGKILL with no graceful handler
that exits `0`) never exits normally, so the OS reports no clean exit code: both
`join()` and `getExitCode()` return **`-1`**. That is the *same* `-1` `join()`
returns for a thread that was **never started**. So `-1` means "no clean exit
code", **not** a specific failure value.

Practical rules:

- Treat outcomes as **`0` = success**, **anything else = failure**. Don't ascribe
  meaning to the exact non-zero number (`-1` for signal death, `1` for a thrown
  task or a rejected payload, etc.).
- Tell "still running" from "finished" with `isAlive()`/`reap()`, not the code.
- If you need to know a worker was *signalled*, track that yourself (you sent the
  signal), or have the task write its own outcome (a file/DB row) before exiting.

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

⚠️ **`detach()` is not the same as [detached mode](09-detached-mode.md).** Use it
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

## What happens when the parent exits

Workers are **independent OS processes**, so the engine does **not** kill them when
your parent ends. Know these behaviors:

- **Parent exits normally.** Destructors run: finished children are reaped,
  still-running ones are *detached* (left running). Those survivors are then
  **reparented to init (PID 1)** and reaped there when they finish — they don't
  die with the parent.
- **Parent crashes hard** (fatal, or its own SIGKILL). Destructors may not run, but
  the children keep running regardless and still reparent to init. Nothing is
  force-stopped for you.
- **Ctrl+C / terminal hang-up.** An **attached** child shares the parent's
  controlling terminal and process group (no `setsid`), so a terminal `SIGINT`
  (Ctrl+C) or `SIGHUP` is delivered to the **whole foreground group** — it hits
  attached workers too. A [detached](09-detached-mode.md) worker is in its own
  session and is **insulated** from these.

If you need children to stop **with** the parent, terminate them explicitly (e.g.
`terminate()`/`kill()` each tracked `Thread` in a shutdown handler) — don't rely on
process exit to do it. If you need them to **outlive** the parent cleanly, use
[detached mode](09-detached-mode.md).

## The non-blocking guarantee

`reap()`, `detach()` and the destructor are guaranteed **non-blocking on a live
process**. The only blocking call, `proc_close`, is invoked **exclusively on an
already-dead process** (inside `join()`'s finish path and inside `reap()` when the
child has exited). `join()` itself blocks — that is its job — but nothing else
will stall your loop.

This is precisely what lets a pool poll hundreds of workers in one tight loop
without any of them holding the loop hostage. See
[11. Architecture](11-architecture.md#building-a-pool-on-launcher--processhandle)
for a complete pool example built on this guarantee.
