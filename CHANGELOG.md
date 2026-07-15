# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2026-07-15

A configuration-model overhaul. The `Engine` layer is gone: `Thread` now binds a
`Launcher` directly, and the parent side is fully interface-driven so the process
backend (proc_open / Docker / SSH / …) can be swapped end to end. The child side is
made symmetric and slimmer. `Thread`'s task-facing API (`start`/`join`/`reap`/…) is
unchanged.

### Changed (BREAKING)
- **`Engine` removed — configuration goes through the `Launcher`.**
  `Thread::bindEngine()` → **`Thread::bindLauncher()`**; `Thread::engine()` →
  **`Thread::launcher()`**. The `Launcher` interface gains **`security()`** (the
  payload-signing provider, previously on `Engine`).
- **`CliLauncher` is now the single configuration object.** Build it self-configured
  with **`CliLauncher::adaptive(secret?, transport?, binaryPath?, runnerPath?)`** or
  explicitly with `new CliLauncher(binaryPath, runnerPath, ?transport, ?secret)`. It
  owns the transport, binary/runner paths, and secret (including the child-env and
  ambient-secret neutralization, previously spread across the two engines).
- **`ProcessHandle` is now an interface.** The concrete `proc_open` implementation is
  **`CliProcessHandle`**. `Launcher::launch()` still returns `ProcessHandle`, so a
  custom launcher may return its own handle. `new ProcessHandle(...)` is no longer
  valid.
- **`PayloadTransport::receive()` removed — transports are parent-side only**
  (`stage()` + `cleanup()`). The child reads the payload itself (STDIN, or shared
  memory by `--shmkey`) inside `AdaptiveRunner`; `PipeTransport`/`TempFileTransport`/
  `ShmTransport` no longer carry a `receive()` method.
- **`AdaptiveRunner` is no longer `final`; its `receive()` is `protected`** — the
  child-side extension seam for custom payload sources.

### Removed
- `Engine` interface, `AdaptiveEngine`, `ManualEngine`, and `Thread::bindEngine()` /
  `Thread::engine()`.
- `PayloadTransport::receive()` and the transports' child-side receive methods.

### Added
- **`CliLauncher::adaptive()` and `AdaptiveRunner::adaptive()`** — symmetric
  self-configuring factories that read the environment (`WINTER_THREAD_SECRET`, PHP
  binary, `wRunner`, Swoole-safe transport).
- **`CliProcessHandle`** — the concrete `proc_open`-backed `ProcessHandle`.
- **`AdaptiveRunner::receive()`** as a `protected` seam for custom child-side
  payload delivery (override it, delegating to `parent::receive()`).

### Changed
- **Transport auto-detection is deferred to `launch()`**, not resolved at
  construction. A launcher bound during **preload** (before a worker/coroutine
  exists) now selects the correct transport when a task is actually started, instead
  of freezing an early, possibly wrong, choice.
- **`wRunner` reduced to a thin bootstrap** — autoload + `AdaptiveRunner::adaptive()->execute()`.
  The secret/verifier construction and the `--debug` error-reporting setup moved into
  the runner.
- Deserialization, signing, escaping, and detached mode are unchanged from 2.0.

### Fixed
- **`join()`/`reap()` drain the child's STDOUT/STDERR pipes while they wait.** With
  `outputTarget: null`, a child that wrote more than the OS pipe buffer (~64 KB)
  could block on `write()` and never exit, so a bare `join()` (without a manual
  drain loop) deadlocked. Draining now happens inside the handle, eliminating the
  whole class of hang.
- **`readOutput()` / `readError()` after `join()` return the full output** instead
  of `''`. Output is buffered during the wait, so it survives the pipes closing on
  completion. The methods are now *consuming* (each returns the bytes since the
  previous call), so incremental poll loops are unaffected.

### Documentation
- The full `docs/` set and README rewritten for the launcher model; the Engine
  chapter became [`07-the-launcher.md`](docs/07-the-launcher.md).
- Reframed the lead: Winter Thread is a **process engine** — the foundation
  queues/pools/schedulers are built on — and a `Thread` is an isolated **OS process**
  wearing a familiar thread-like API (à la Python's `multiprocessing.Process`), not
  a real in-process PHP thread.

### Known limitations
- **Swoole:** running from *inside a live Swoole coroutine worker* is experimental
  and under active development — native `proc_open` contends with the Swoole reactor
  over the process file-descriptor table. `CliLauncher::adaptive()` still selects a
  pipe-free transport under Swoole, but that alone is not sufficient. Plain CLI and
  FPM are unaffected. See [`docs/08`](docs/08-payload-transports.md#swoole--event-loop-compatibility).

### Migration from 2.x
- Replace `Thread::bindEngine(new AdaptiveEngine(...))` with
  `Thread::bindLauncher(CliLauncher::adaptive(...))`.
- Replace `(new ManualEngine())->withTransport(t)->withBinaryPath(b)->withRunnerPath(r)->withSecurity(s)`
  with `new CliLauncher(binaryPath: b, runnerPath: r, transport: t, secret: s)`.
- Replace a custom-launcher engine (`AdaptiveEngine(launcher: $l)` /
  `withLauncher($l)`) with `Thread::bindLauncher($l)` directly.
- If you constructed `ProcessHandle` directly, use `CliProcessHandle`.
- A custom `PayloadTransport` now implements only `stage()`/`cleanup()`; move any
  child-side receiving into a subclass of `AdaptiveRunner` (override `receive()`).

## [2.0.0] - 2026-07-03

A ground-up redesign. `Thread` keeps its familiar Java-like API but is now a thin
facade over small, composable primitives you can build a pool/scheduler on.

### Added
- **`Engine` abstraction** bound once via `Thread::bindEngine()`:
  - `AdaptiveEngine` — self-configuring default (detects CLI/FPM binary, Swoole
    runtime, and `WINTER_THREAD_SECRET`);
  - `ManualEngine` — explicit, immutable withers for full control.
- **Parent-side primitives** for framework authors: `Launcher` / `CliLauncher`,
  `ProcessHandle`, and the `LaunchSpec` DTO — drive processes without the `Thread`
  facade.
- **Pluggable payload transports**: `PipeTransport`, `TempFileTransport` (Swoole-safe),
  and `ShmTransport` (System V shared memory; needs `ext-shmop`). The engine
  auto-selects a Swoole-safe transport under an active Swoole runtime.
- **Child-side `Runner`** (`AdaptiveRunner`), driven by the `wRunner` bootstrap and
  deliberately independent of the parent `Engine`.
- **Non-blocking lifecycle**: `reap()`, `detach()`, `getExitCode()`, and a
  zombie-safe destructor — the basis for polling many workers in one loop.
- **Detached mode** (`start(detached: true)`): `fork` + `setsid` for zombie-free
  fire-and-forget under a long-lived parent.
- **Signed payloads** via `opis/closure` (HMAC), with the secret propagated to the
  child through the environment (never argv).
- Full documentation set under [`docs/`](docs/README.md) and a two-tier test suite
  (default + container) with CI across PHP 8.4 / 8.5.

### Changed
- **Requires PHP >= 8.4** (`readonly` classes, first-class callable syntax).
- Deserialization now goes **exclusively** through `opis/closure` — native
  `unserialize()` is never used.
- CLI arguments (binary/runner paths, metadata, per-run args, shm key) are uniformly
  escaped with `escapeshellarg()`.

### Security
- Signing secret is transmitted via the owner-only environment, never on the command
  line.
- An ambient `WINTER_THREAD_SECRET` in the parent environment is neutralized for the
  child when the engine is unsigned, preventing spurious "failed to deserialize"
  rejections.

[3.0.0]: https://github.com/flytachi/winter-thread/compare/v2.0.0...v3.0.0
[2.0.0]: https://github.com/flytachi/winter-thread/releases/tag/v2.0.0
