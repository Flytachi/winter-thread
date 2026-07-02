# 6. The Engine

Everything configurable lives behind a single abstraction: the
[`Engine`](../src/Engine/Engine.php). It decides *how* a task is delivered and
executed — the payload transport, the launcher, the child-side runner, the PHP
binary and runner paths, and the optional signing secret.

You bind one **once at bootstrap**:

```php
Thread::bindEngine($engine);
```

If you bind nothing, the default [`AdaptiveEngine`](../src/Engine/AdaptiveEngine.php)
is used — so the zero-config case just works.

## The `Engine` contract

```php
interface Engine
{
    public function transport(): PayloadTransport;   // how the payload is delivered
    public function launcher(): Launcher;            // how the process is spawned
    public function runner(): Runner;                // how the child runs the task
    public function binaryPath(): string;            // PHP CLI binary
    public function runnerPath(): string;            // wRunner bootstrap script
    public function security(): ?DefaultSecurityProvider; // payload signing
}
```

Two implementations ship with the library.

## `AdaptiveEngine` — self-configuring (default)

Detects the environment at construction and picks sensible defaults; every part
is overridable through the constructor.

```php
new AdaptiveEngine(
    secret:     null,   // else WINTER_THREAD_SECRET env, else no signing
    transport:  null,   // else auto: TempFile under Swoole, otherwise Pipe
    binaryPath: null,   // else the resolved real PHP CLI binary
    runnerPath: null,   // else the packaged wRunner
    launcher:   null,   // else the default CliLauncher
);
```

What it auto-detects:

- **Transport** — [`TempFileTransport`](07-payload-transports.md) when a Swoole
  runtime is active (inside a coroutine, or with runtime hooks enabled), otherwise
  [`PipeTransport`](07-payload-transports.md).
- **Binary path** — under a non-CLI SAPI (FPM/CGI) it resolves a real PHP CLI
  binary instead of the web handler.
- **Secret** — the explicit argument, else the `WINTER_THREAD_SECRET` env var,
  else none.

Because it's the default, most applications never touch the engine at all:

```php
$thread = new Thread(new MyTask());
$thread->start(); // AdaptiveEngine, fully configured
```

## `ManualEngine` — explicit (clean slate)

Detects nothing. You set each part with immutable withers; a required part left
unset throws `ThreadException` when accessed. Predictable, no environment magic.

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

## Injecting a custom launcher

The `Launcher` is the seam for new backends (Docker, SSH, remote nodes). Provide
a built instance and the engine returns it as-is; wiring transport/secret into a
custom backend is your responsibility.

```php
new AdaptiveEngine(launcher: new MySshLauncher(/* host, key, … */));
(new ManualEngine())->withLauncher(new MyDockerLauncher(/* image, … */));
```

Interfaces are the only extension points that need them (`Engine`, `Launcher`,
`Runner`, `PayloadTransport`); `ProcessHandle` and `LaunchSpec` are concrete
value/handle types. See [10. Architecture](10-architecture.md).

## Resetting the engine

`bindEngine()` sets a process-wide static. To go back to defaults, bind a fresh
`AdaptiveEngine`:

```php
Thread::bindEngine(new AdaptiveEngine());
```
