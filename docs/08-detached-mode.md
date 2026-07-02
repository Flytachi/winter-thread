# 8. Detached Mode

Detached mode makes fire-and-forget **zombie-free** under a long-lived parent
(an FPM worker, a daemon). Pass `detached: true` to `start()`:

```php
$thread = new Thread(new SendEmailBatch($ids));
$thread->start(detached: true);   // returns at once; you never join it
```

> Don't confuse this with [`detach()`](05-process-control.md#giving-up-ownership-detach):
> `detach()` *abandons tracking* of an ordinary child (which can still become a
> zombie under a long-lived parent); **detached mode** *re-parents* the worker to
> init so it is always reaped. They solve different problems — see
> [the comparison below](#detached-mode-vs-detach).

## The problem it solves

When you spawn a task and never `join()`/`reap()` it, the finished child becomes
a **zombie** — a dead process the OS keeps in the table until its parent collects
it (so the parent could still read its exit status).

For a short-lived CLI script this doesn't matter: the OS reaps everything when the
script exits moments later. But a **long-lived parent** — an FPM worker handling
thousands of requests, or a daemon running for days — accumulates **one zombie per
fired task**, and eventually hits the per-process or system-wide PID limit and can
no longer fork. Detached mode removes the parent from the picture entirely.

## How it works

In detached mode the child **daemonizes** with a single `fork` + `setsid`, *after*
it has already received and deserialized the payload:

```
parent ──proc_open──▶ launcher process (php wRunner)
                          │  1. receive payload  →  deserialize + verify
                          │  2. fork()
                          ├── launcher  → exit(0)     ← dies immediately
                          └── worker
                               3. setsid()            ← new session, no controlling tty
                               4. set process title
                               5. run task; exit(code)
```

- The **launcher** process exits instantly with code `0`, so the parent reaps it
  cheaply — its `join()`/`reap()` returns at once and it never becomes a zombie.
- The **worker** is now orphaned (its parent, the launcher, is gone) and is
  therefore **reparented to init (PID 1)**, which reaps it when it finishes. It
  never becomes a zombie under *your* parent.
- **`setsid()`** puts the worker in a new session with no controlling terminal, so
  terminal-directed signals (SIGINT/SIGHUP from the parent's tty) don't reach it.

The payload is fully read and deserialized **before** the fork, so no transport
resource (pipe, temp file, shm) is shared across the fork — the worker already
holds the reconstructed `Runnable` in memory.

Requires `ext-pcntl` (`fork`) and `ext-posix` (`setsid`) — both are already
package dependencies. Crucially, **the fork happens inside the clean `wRunner`
process, not inside your Swoole/FPM parent**, so none of your app's open
descriptors or event loop are duplicated — it is safe even under a Swoole runtime.

## The returned PID is the launcher's

`start(detached: true)` returns the **launcher's ephemeral PID** — the process
that immediately exits — **not** the real worker's. Because of that:

- `getPid()` on the `Thread` is not the worker; signalling through the `Thread`
  (`terminate()`, `kill()`, …) won't reach the worker (the launcher is already
  gone, so those calls just return `false`).
- The worker has its **own** PID, discoverable only from inside `run()`.

### Signal control still works — via a self-reported PID

The pattern — used by frameworks built on this engine — is for the task to
**self-report its real PID** from inside `run()` and to signal that PID with the
[`Signal`](../src/Signal.php) helper:

```php
public function run(array $args): void
{
    $myPid = getmypid();
    Registry::store($args['job'], $myPid);   // e.g. Redis/DB/file
    // … long-running work …
}

// elsewhere, a supervisor:
Signal::terminationAndWait(Registry::pid($jobId));  // SIGTERM + wait
```

The engine's control model is **PID-based**, so once you know the worker's PID the
full `Signal` API applies identically to attached and detached workers. `Signal`
also detects zombies correctly, so a just-exited worker reads as not-running.

## Output with detached mode

Because the launcher exits immediately and the parent isn't waiting, **don't** use
`outputTarget: null` with detached mode — there is no reader for the pipes. Use
the default `/dev/null`, or point at a **file** so the worker's output (and any
uncaught-exception trace) is preserved:

```php
$thread->start(detached: true, outputTarget: '/var/log/app/emails.log');
```

## Containers: give PID 1 a reaper

Reparenting relies on **PID 1 being a real init that reaps orphans**. On a normal
OS that's `systemd`/`launchd`. In a bare container, PID 1 is often *your own app*,
which does **not** reap orphans — so detached workers would reparent to it and pile
up as zombies.

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

With an init present, detached workers reparent to it and are reaped cleanly. This
only matters for **detached** tasks; attached tasks you `join()`/`reap()` yourself
don't need it.

## Detached mode vs. `detach()`

| | `start(detached: true)` | `detach()` |
|---|---|---|
| What it does | worker forks + `setsid`, reparents to init | parent stops tracking an ordinary child |
| Zombie under a long-lived parent? | **no** — init reaps it | **yes**, until the parent exits |
| Blocks? | no | no |
| Exit code available? | no (worker is independent) | no (never reaped here) |
| Needs a container init? | yes, if your app is PID 1 | no |
| Use when | fire-and-forget under FPM/daemon | short script that exits soon after |

## When to use it

- **Use detached mode** for fire-and-forget under a **long-lived** parent (FPM,
  daemons) — the only reliable way to avoid zombie build-up there.
- **Don't bother** for short CLI scripts that exit soon after — the OS reaps
  everything on exit; a plain `start()` (optionally `detach()`) is simpler.
- **Don't detach if you need the result** — use `start()` then `join()`/`reap()`
  and read the exit code.
