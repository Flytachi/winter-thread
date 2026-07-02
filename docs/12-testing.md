# 12. Testing

The test suite is organized in two tiers, mirroring the `winter-kernel` layout:
a **default** tier that runs anywhere, and a **container** tier for heavy,
environment-specific checks.

## Default tier — runs on any machine

`composer test` runs the `base` and `working` suites. Anything requiring an
absent extension self-skips, so it is always green on a plain PHP install.

```bash
composer install
composer test            # base + working
composer test-base       # unit-level class correctness
composer test-working    # end-to-end scenarios
composer test-detail     # testdox (human-readable) output
```

- **base** (`tests/Base`) — correctness of each class in isolation, including the
  non-blocking guarantees of `reap()` / `detach()` / the destructor.
- **working** (`tests/Working`) — real end-to-end scenarios: every transport,
  signals, arguments, output modes, the pool reap-loop, detached reparenting, and
  fault-tolerance (bad binary/runner, double-start, throwing tasks).

## Container tier — heavy & environment-specific

The `container` suite (`tests/Container`) is **excluded from the default run** and
executed inside Docker, where `/proc`, Swoole, and a reaping init are available.

```bash
tests/run-container.sh              # default versions: 8.4 8.5
tests/run-container.sh 8.4          # a single version
tests/run-container.sh 8.4 8.5 8.6  # a custom list

# Inside an environment that already has swoole/shmop:
composer test-container             # phpunit --testsuite container
```

It covers, per PHP version:

- **Leak** — no zombies, no file-descriptor growth, no memory growth across many
  spawns; the FPM long-lived-parent scenario; and security (payload absent from
  `/proc/<pid>/cmdline`, unsigned-payload rejection).
- **Timing** — start-up latency per transport, and proof that detached start does
  not block on the task.
- **Swoole** — payload delivered intact inside a hooked coroutine and with the
  runtime dormant.
- **Payload** — a payload far larger than a pipe buffer is delivered byte-intact.
- **Load** — concurrency stress (40 / 100 at once, 300 through a bounded pool)
  with a printed throughput/fd/zombie summary.
- **Metrics** — a printed worker RSS report (default build vs a lean `php -n`
  worker).
- **Nested / Workload** — a `Thread` inside a `Thread` (three levels), and a
  mixed "battle run" of a dozen heterogeneous jobs verified against expected
  results.

## The container image

`tests/docker/Dockerfile` builds `php:<version>-cli` with `pcntl`, `posix`,
`shmop`, and `swoole` (plus `procps` for `ps`/`/proc`). The compose file runs the
container with `init: true` so detached workers are reaped by tini.

## Continuous integration

`.github/workflows/ci.yml` runs two jobs across a PHP `8.4` / `8.5` matrix:

- **default** — via `setup-php`, runs code style (`phpcs`) then `composer test`;
- **container** — builds the Docker image (with layer caching) and runs the
  default + container suites inside it (`docker run --init`).

Third-party actions are pinned to commit SHAs for supply-chain safety.

## Code style

```bash
composer cs-check   # phpcs
composer cs-fix     # phpcbf
```
