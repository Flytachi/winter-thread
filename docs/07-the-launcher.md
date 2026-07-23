# 7. The Launcher

Everything about *how a task process is started* lives behind a single
parent-side abstraction: the [`Launcher`](../src/Launch/Launcher.php). It spawns
the process and owns the parent↔child trust contract (the payload-signing
secret). The default [`AdaptiveLauncher`](../src/Launch/AdaptiveLauncher.php)
routes each launch to a concrete backend — [`CliLauncher`](../src/Launch/CliLauncher.php)
or [`SwooleLauncher`](../src/Launch/SwooleLauncher.php) — each of which additionally
carries the payload transport, the PHP binary path, and the `wRunner` path — but
those are the backend's own concern, not part of the `Launcher` interface.

You bind one **once at bootstrap**:

```php
Thread::bindLauncher($launcher);
```

If you bind nothing, a self-configuring `AdaptiveLauncher` is used — so the
zero-config case just works everywhere, CLI, FPM, and inside a Swoole coroutine.
The binding is a process-wide static shared by every `Thread` in the process.

## The `Launcher` contract

```php
interface Launcher
{
    public function launch(LaunchSpec $spec): ProcessHandle;   // spawn; return a control handle
    public function security(): ?DefaultSecurityProvider;      // payload signing (or null)
}
```

Just two methods — that is the entire surface `Thread` consumes. Everything else
(transport, binary path, runner path) is an implementation detail of the concrete
launcher, so a custom backend (Docker, SSH, …) implements only what it actually
needs.

## The Launcher is parent-side only

The `Launcher` lives entirely in **your process** (the parent). `Thread` uses it
to `launch()` the process and to obtain the `security()` provider for signing the
serialized task. It is **not** shipped to the child: running the task in the
worker is the job of a separate [`AdaptiveRunner`](11-architecture.md), which the
`wRunner` bootstrap constructs on its own. Launcher (parent) and Runner (child)
are independent — they don't reference each other; the only things that cross the
boundary are the payload, a couple of CLI flags, and the secret (via env).

Two consequences follow directly, and they explain the whole model:

1. **The secret reaches the child through the environment, not through your bound
   object.** When the launcher has a secret, the built-in `CliLauncher` injects it
   into the child's environment as `WINTER_THREAD_SECRET`. The child (`wRunner`)
   reads that env var **directly** — via [`AdaptiveRunner::adaptive()`](../src/Runner/AdaptiveRunner.php) —
   to build its verifier. (See [10. Security](10-security.md) for why env and not argv.)
2. **The child picks how it receives the payload from CLI options, not from your
   bound transport.** If a `--shmkey` option is present the child reads shared
   memory; otherwise it reads STDIN (which serves both the pipe and temp-file
   deliveries identically). The transport is a **parent-only** strategy for
   *staging* the payload — it no longer has a child-side half.

> **Implication for custom launchers.** If you replace the `Launcher` (SSH,
> Docker, remote node), *you* become responsible for running the correct `wRunner`
> on the far side, delivering the payload to its STDIN (or shm), and — if you sign
> — forwarding `WINTER_THREAD_SECRET` into the remote environment. The default
> `CliLauncher` does all of this locally.

## `AdaptiveLauncher` — the default

The default launcher is `AdaptiveLauncher`. It holds both backends and picks one
**per `launch()`**, from the runtime it finds at that moment:

- inside a Swoole coroutine, or with runtime hooks enabled → **`SwooleLauncher`**,
  which is safe against the reactor;
- everywhere else — plain CLI, FPM → **`CliLauncher`**, which uses `proc_open`.

`Thread::launcher()` builds `AdaptiveLauncher::adaptive()` when nothing is bound, so
the same code launches background tasks correctly from a CLI command and from
inside an HTTP-worker coroutine with no configuration. With no Swoole runtime
active it routes to `CliLauncher` and behaves exactly like previous versions. The
two backends it routes between are described next.

## `CliLauncher` — the CLI / FPM backend

Spawns a local PHP CLI process with `proc_open`. Build it two ways.

### Self-configuring: `CliLauncher::adaptive()`

Resolves sensible defaults for the current environment; every part is overridable
per argument.

```php
CliLauncher::adaptive(
    secret:     null,   // else WINTER_THREAD_SECRET env, else no signing
    transport:  null,   // else auto-detected per launch (see below)
    binaryPath: null,   // else the resolved real PHP CLI binary
    runnerPath: null,   // else the packaged wRunner
);
```

What it resolves, exactly:

- **Secret** — the explicit `secret` argument, else the `WINTER_THREAD_SECRET`
  environment variable, else `null` (no signing). Read once, at construction.
- **Binary path** — under a CLI SAPI (`cli`/`cli-server`) it uses `PHP_BINARY`
  (the running interpreter); under a non-CLI SAPI (FPM/CGI) it resolves
  `PHP_BINDIR . '/php'` if that is executable, else falls back to `'php'` on
  `PATH`. This is what makes it work correctly under FPM.
- **Runner path** — the `wRunner` script in the installed package root.
- **Transport** — **not** resolved at construction. When left `null`, the
  transport is auto-detected on **every `launch()`** (see below).

Because the default `AdaptiveLauncher` routes here for CLI/FPM, most applications
never touch the launcher at all:

```php
$thread = new Thread(new MyTask());
$thread->start(); // AdaptiveLauncher → self-configuring CliLauncher on CLI/FPM
```

### Transport is detected per launch, not at bind time

A `null` transport is resolved inside `launch()`, not when the launcher is built.
Detection picks [`TempFileTransport`](08-payload-transports.md) when a Swoole
runtime is active (inside a coroutine, or with runtime hooks enabled) and
[`PipeTransport`](08-payload-transports.md) otherwise.

Deferring this to launch time is deliberate: a launcher bound during **preload**
(before any worker/coroutine exists) would otherwise freeze the wrong choice.
Resolving per launch means the transport reflects the environment as it is when a
task is actually started.

> **Swoole note.** `CliLauncher` uses `proc_open`, which corrupts the reactor's
> file descriptors when called from *inside a live Swoole coroutine* — the second
> spawn fails with `Bad file descriptor`. You do not normally hit this: the default
> `AdaptiveLauncher` routes to `SwooleLauncher` there. Bind a bare `CliLauncher`
> only for CLI/FPM, or when you know no coroutine is active.

### Explicit construction

Pass every part yourself — reproducible across CLI/FPM/containers, no
environment magic:

```php
Thread::bindLauncher(new CliLauncher(
    binaryPath: '/usr/bin/php',
    runnerPath: __DIR__ . '/vendor/flytachi/winter-thread/wRunner',
    transport:  new TempFileTransport(),   // omit (null) to auto-detect per launch
    secret:     'your-signing-secret',     // omit for no signing
));
```

`CliLauncher` is a `final readonly class` — immutable once constructed. To change
one aspect, construct a new one.

## `SwooleLauncher` — the coroutine backend

The backend `AdaptiveLauncher` routes to when a Swoole runtime is active. You
rarely build it directly; it exists so that launching from *inside a coroutine*
works at all.

Instead of `proc_open` (which corrupts the reactor's fds) or `Swoole\Process`
(which Swoole forbids while its async-io threads are up), it starts the runner as
a **shell background job** through `Swoole\Coroutine\System::exec()` — non-blocking
inside a coroutine, and never touching the reactor's descriptors. The runner then
daemonizes itself (`--detach`) and re-parents to init, so the launch returns
immediately.

The payload is delivered pipe-free — the fd type a pipe would use is exactly what
conflicts with the reactor:

- with `ext-shmop`: through shared memory, passed by `--shmkey` (RAM, no file);
- otherwise: through a temp file read as the child's stdin.

```php
SwooleLauncher::adaptive(
    secret:     null,   // else WINTER_THREAD_SECRET env, else no signing
    binaryPath: null,   // else the resolved real PHP CLI binary
    runnerPath: null,   // else the packaged wRunner
);
```

Requires `ext-swoole` at launch time (it throws otherwise) and is meaningful only
for **detached** tasks — a coroutine worker has no parent pipe to stream a child's
output back through, so its `ProcessHandle` ({@see SwooleProcessHandle}) is
PID-based: liveness and signalling work, but there is nothing to `join()` or read.

## A custom backend

The `Launcher` is the seam for new backends (Docker, SSH, remote nodes). Implement
its two methods:

```php
final class MyDockerLauncher implements Launcher
{
    public function launch(LaunchSpec $spec): ProcessHandle { /* docker run … */ }
    public function security(): ?DefaultSecurityProvider { return null; } // or your provider
}

Thread::bindLauncher(new MyDockerLauncher(/* image, … */));
```

Because [`ProcessHandle`](../src/Launch/ProcessHandle.php) is an **interface**, a
custom launcher returns its own handle implementation (the default
`CliLauncher` returns a `CliProcessHandle` wrapping a `proc_open` resource). The
whole parent side — `Thread` and any worker pool — programs against the
`Launcher` and `ProcessHandle` interfaces, so nothing else changes when you swap
the backend. Wiring the transport/secret/`wRunner` into a remote backend is your
responsibility (see [the distinction above](#the-launcher-is-parent-side-only)).

The extension points that need implementing are interfaces: `Launcher`,
`ProcessHandle`, `Runner`, `PayloadTransport`; `LaunchSpec` is a concrete value
type you consume. See [11. Architecture](11-architecture.md).

## Accessing the launcher directly

Framework code that builds its own pool can reach the launcher and handle without
the `Thread` facade:

```php
$launcher = Thread::launcher();        // the bound launcher (lazily an AdaptiveLauncher)
$handle   = $launcher->launch($spec);  // a ProcessHandle for a LaunchSpec
```

`Thread::launcher()` returns the currently bound launcher, lazily creating a
self-configuring `AdaptiveLauncher` the first time if none was bound.

## Resetting the launcher

`bindLauncher()` sets a process-wide static. To go back to defaults, bind a fresh
one:

```php
Thread::bindLauncher(AdaptiveLauncher::adaptive());
```

There is no separate "unbind" — rebinding replaces the previous launcher for all
subsequent `Thread` operations in the process.
