# Contributing to Winter Thread

Thanks for helping improve Winter Thread! This guide covers local setup, the test
tiers, and the conventions the project follows.

## Requirements

- **PHP >= 8.4** with `ext-pcntl` and `ext-posix` (and `ext-shmop` if you touch the
  shared-memory transport).
- Composer.
- A POSIX OS (Linux or macOS) — the engine relies on signals, `setsid`, and
  `/proc`/`ps`. Windows is not supported.

## Setup

```bash
git clone https://github.com/flytachi/winter-thread
cd winter-thread
composer install
```

## Running the tests

The suite has **two tiers** (see [docs/15 — Testing](docs/15-testing.md)).

**Default tier** — runs on any machine; tests needing an absent extension self-skip:

```bash
composer test            # base (unit) + working (end-to-end)
composer test-base       # unit-level class correctness only
composer test-working    # real end-to-end scenarios only
composer test-detail     # testdox (human-readable) output
```

**Container tier** — heavy, environment-specific checks (leak / timing / Swoole /
load), run inside Docker across PHP versions:

```bash
tests/run-container.sh              # default versions: 8.4 8.5
tests/run-container.sh 8.4          # a single version
```

## Code style

PSR-12, enforced by `phpcs`:

```bash
composer cs-check   # report violations (src/)
composer cs-fix     # auto-fix what it can
```

All `src/` files must declare `strict_types=1`. CI runs `cs-check` + the default
suite on a PHP 8.4 / 8.5 matrix, and the container suite in Docker.

## Pull requests

1. **Branch** off `main`.
2. **Add tests** for any behavior change — a bug fix gets a regression test; a
   feature gets coverage in the appropriate tier (`tests/Base` for isolated class
   correctness, `tests/Working` for end-to-end, `tests/Container` for
   environment-specific).
3. **Keep docs in sync.** The `docs/*` files are the source of truth for behavior;
   if you change a signature or a default, update the matching doc (especially
   [docs/14 — API Reference](docs/14-api-reference.md)) in the same PR.
4. **Run `composer cs-check` and `composer test`** locally; both must be green.
5. Keep changes focused; explain the *why* in the PR description.

## Project layout

- `src/` — the library. `Thread` is a thin facade; the real work lives in the
  `Launcher` / `ProcessHandle` / `Runner` / `PayloadTransport` primitives. Read
  [docs/11 — Architecture](docs/11-architecture.md) before making structural
  changes — note that the **parent-side `Launcher`** and the **child-side `Runner`**
  are deliberately independent.
- `wRunner` — the child bootstrap script (packaged as `bin`).
- `tests/` — `Base` / `Working` / `Container` tiers, plus `Fixtures` and `docker`.
- `docs/` — the documentation set.

## Reporting bugs & security issues

- **Bugs:** open a GitHub issue with a minimal reproduction, your PHP version, and
  OS.
- **Security vulnerabilities:** do **not** open a public issue — follow
  [SECURITY.md](SECURITY.md).

## License

By contributing you agree that your contributions are licensed under the project's
[MIT license](LICENSE).
