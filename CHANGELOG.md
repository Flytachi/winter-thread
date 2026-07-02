# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

_Nothing yet._

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

[Unreleased]: https://github.com/flytachi/winter-thread/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/flytachi/winter-thread/releases/tag/v2.0.0
