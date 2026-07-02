# 7. The Engine

Everything configurable lives behind a single abstraction: the
[`Engine`](../src/Engine/Engine.php). It decides *how* a task is delivered and
executed — the payload transport, the launcher, the child-side runner, the PHP
binary and runner paths, and the optional signing secret.

You bind one **once at bootstrap**:

```php
Thread::bindEngine($engine);
```

If you bind nothing, the default [`AdaptiveEngine`](../src/Engine/AdaptiveEngine.php)
is used — so the zero-config case just works. The binding is a process-wide
static shared by every `Thread` in the process.

## The `Engine` contract

```php
interface Engine
{
    public function transport(): PayloadTransport;        // how the payload is delivered
    public function launcher(): Launcher;                 // how the process is spawned
    public function runner(): Runner;                     // how the child runs the task
    public function binaryPath(): string;                 // PHP CLI binary
    public function runnerPath(): string;                 // wRunner bootstrap script
    public function security(): ?DefaultSecurityProvider; // payload signing (or null)
}
```

Two implementations ship with the library: `AdaptiveEngine` (self-configuring,
the default) and `ManualEngine` (explicit, clean slate).

## Parent side vs. child side — a crucial distinction

The engine is used in **two different processes**, and they do not share the same
object:

- **Parent (your app).** The engine you `bindEngine()` is used here to
  `serialize()` the task, choose the `transport()`, build the `launcher()`, resolve
  `binaryPath()`/`runnerPath()`, and sign the payload via `security()`.
- **Child (`wRunner`).** The bootstrap script **always constructs a fresh
  `new AdaptiveEngine()`** — regardless of what you bound in the parent — and uses
  *its* `runner()` and `security()` to receive, verify, and run the task.

Two consequences follow directly, and they explain the whole configuration model:

1. **The secret reaches the child through the environment, not through your bound
   object.** When the parent's engine has a secret, the built-in
   [`CliLauncher`](11-architecture.md) injects it into the child's environment as
   `WINTER_THREAD_SECRET`. The child's `AdaptiveEngine` reads that env var in its
   `security()` and verifies the signature. So signing works even if the parent
   used a `ManualEngine` with an explicit secret — the value is propagated for you.
   (See [10. Security](10-security.md) for why env and not argv.)
2. **The child picks its receiving transport from CLI options, not from your bound
   transport.** If a `--shmkey` option is present the child reads shared memory;
   otherwise it reads STDIN (which serves both the pipe and temp-file transports
   identically). This is why parent and child stay consistent without shipping the
   whole engine across the boundary.

> **Implication for custom launchers.** If you replace the `Launcher` (SSH,
> Docker, remote node), *you* become responsible for running the correct `wRunner`
> on the far side, delivering the payload to its STDIN (or shm), and — if you sign
> — forwarding `WINTER_THREAD_SECRET` into the remote environment. The default
> `CliLauncher` does all of this locally.

## `AdaptiveEngine` — self-configuring (default)

Detects the environment at construction and picks sensible defaults; every part
is overridable through the constructor.

```php
new AdaptiveEngine(
    secret:     null,   // else WINTER_THREAD_SECRET env, else no signing
    transport:  null,   // else auto: TempFile under an active Swoole runtime, else Pipe
    binaryPath: null,   // else the resolved real PHP CLI binary
    runnerPath: null,   // else the packaged wRunner
    launcher:   null,   // else the default CliLauncher (built from the above)
);
```

What it resolves, exactly:

- **Secret** — the explicit `secret` argument, else the `WINTER_THREAD_SECRET`
  environment variable, else `null` (no signing).
- **Transport** — [`TempFileTransport`](08-payload-transports.md) **only when a
  Swoole runtime is active** — detected as being inside a coroutine
  (`\Swoole\Coroutine::getCid() !== -1`) *or* with runtime hooks enabled
  (`\Swoole\Runtime::getHookFlags() !== 0`) — otherwise
  [`PipeTransport`](08-payload-transports.md). If the `swoole` extension isn't
  loaded at all, it's always Pipe.
- **Binary path** — under a CLI SAPI (`cli`/`cli-server`) it uses `PHP_BINARY`
  (the running interpreter); under a non-CLI SAPI (FPM/CGI) it resolves
  `PHP_BINDIR . '/php'` if that is executable, else falls back to `'php'` on
  `PATH`. This is what makes it work correctly under FPM.
- **Runner path** — the `wRunner` script in the installed package root.
- **Launcher** — if you pass a `launcher`, it is returned **as-is**; otherwise a
  `CliLauncher` is built from the resolved binary/runner/transport plus the
  child-env (which carries the secret).

Because it's the default, most applications never touch the engine at all:

```php
$thread = new Thread(new MyTask());
$thread->start(); // AdaptiveEngine, fully configured
```

`AdaptiveEngine` is a `final readonly class` — immutable once constructed. To
change one aspect, construct a new one with the relevant named argument.

## `ManualEngine` — explicit (clean slate)

Detects **nothing**. You set each part with immutable withers (each returns a
*clone* — the original is untouched). A required part left unset **throws**
`ThreadException` when accessed. Predictable, no environment magic.

```php
Thread::bindEngine(
    (new ManualEngine())
        ->withTransport(new TempFileTransport())
        ->withBinaryPath('/usr/bin/php')
        ->withRunnerPath(__DIR__ . '/vendor/flytachi/winter-thread/wRunner')
        ->withSecurity('your-signing-secret')
        ->withLauncher(new MyCustomLauncher()) // optional
);
```

Which parts are required depends on how the launcher is resolved:

- **Default launcher path** — `transport`, `binaryPath` and `runnerPath` must all
  be set (each getter throws `"ManualEngine: <part> is not configured."` if not).
  `secret` is optional (no signing when absent).
- **Custom launcher path** — if you set `withLauncher(...)`, that launcher is
  returned as-is and the engine does **not** require `binaryPath`/`runnerPath`/
  `transport` — your launcher owns its own wiring.

Use `ManualEngine` when you want an explicit, environment-independent config
(reproducible across CLI/FPM/containers) or a custom backend.

## Injecting a custom launcher

The `Launcher` is the seam for new backends (Docker, SSH, remote nodes). Provide
a built instance and the engine returns it unchanged; wiring transport/secret/
`wRunner` into a custom backend is your responsibility (see
[the distinction above](#parent-side-vs-child-side--a-crucial-distinction)).

```php
new AdaptiveEngine(launcher: new MySshLauncher(/* host, key, … */));
(new ManualEngine())->withLauncher(new MyDockerLauncher(/* image, … */));
```

Interfaces are the only extension points that need implementing (`Engine`,
`Launcher`, `Runner`, `PayloadTransport`); `ProcessHandle` and `LaunchSpec` are
concrete value/handle types you consume, not implement. See
[11. Architecture](11-architecture.md).

## Accessing the engine directly

Framework code that builds its own pool can reach the launcher and handle without
the `Thread` facade:

```php
$engine   = Thread::engine();          // the bound engine (lazily an AdaptiveEngine)
$launcher = $engine->launcher();       // the parent-side spawn strategy
$handle   = $launcher->launch($spec);  // a ProcessHandle for a LaunchSpec
```

`Thread::engine()` returns the currently bound engine, lazily creating a default
`AdaptiveEngine` the first time if none was bound.

## Resetting the engine

`bindEngine()` sets a process-wide static. To go back to defaults, bind a fresh
`AdaptiveEngine`:

```php
Thread::bindEngine(new AdaptiveEngine());
```

There is no separate "unbind" — rebinding replaces the previous engine for all
subsequent `Thread` operations in the process.
