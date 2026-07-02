# 8. Detached Mode

Detached mode makes fire-and-forget **zombie-free** under a long-lived parent
(an FPM worker, a daemon). Pass `detached: true` to `start()`:

```php
$thread = new Thread(new SendEmailBatch($ids));
$thread->start(detached: true);   // returns at once; you never join it
```

## The problem it solves

When you spawn a task and never `join()`/`reap()` it, the finished child becomes
a **zombie** — a dead process the OS keeps around until its parent collects it.
For a short-lived CLI script this doesn't matter (the OS reaps everything when the
script exits). But a **long-lived parent** — an FPM worker handling thousands of
requests, or a daemon running for days — accumulates one zombie per fired task
until it eventually hits the process limit.

## How it works

In detached mode the child **daemonizes** with a single `fork` + `setsid`:

```
parent ──proc_open──▶ launcher (php wRunner)
                          │  reads payload, deserializes
                          fork()
                          ├── launcher  → exit(0)    ← dies immediately
                          └── worker
                               setsid()              ← new session, no controlling tty
                               run task; exit(code)
```

- The **launcher** exits instantly, so the parent reaps it cheaply (its
  `join()`/`reap()` returns at once) — no zombie from it.
- The **worker** is orphaned (its parent, the launcher, is gone) and therefore
  **reparented to init (PID 1)**, which reaps it when it finishes. It never
  becomes a zombie under your parent.
- `setsid()` detaches the worker from the controlling terminal, so terminal
  signals (SIGINT/SIGHUP) to the parent don't reach it.

Requires `ext-pcntl` (`fork`) and `ext-posix` (`setsid`) — both are already
dependencies. Note the fork happens inside the clean `wRunner` process, **not**
inside your Swoole/FPM parent, so it is safe even under a Swoole event loop.

## Signal control still works

`start(detached: true)` returns the launcher's (ephemeral) PID, so you can't
signal the worker through the `Thread` afterwards. The pattern — used by
frameworks built on this engine — is for the task to **self-report its real PID**
from inside `run()` (e.g. `getmypid()` written to a shared store), and to signal
that PID via the [`Signal`](../src/Signal.php) helper. The engine's control model
is PID-based, so detaching costs you nothing here.

## Containers: give PID 1 a reaper

Reparenting relies on **PID 1 being a real init that reaps orphans**. In a normal
OS that's `systemd`/`launchd`. In a bare container, PID 1 is often your own app —
which does *not* reap orphans, so detached workers would pile up under it.

Fix it by giving the container a reaping init:

```bash
docker run --init …            # tini as PID 1
```

```yaml
# docker-compose
services:
  app:
    init: true
```

With an init present, detached workers reparent to it and are reaped cleanly.

## When to use it

- **Use detached** for fire-and-forget under a long-lived parent (FPM, daemons).
- **Don't bother** for short CLI scripts that exit soon after — the OS reaps
  everything on exit; a plain `start()` is fine.
- If you need the result, don't detach — `start()` then `join()`/`reap()`.
