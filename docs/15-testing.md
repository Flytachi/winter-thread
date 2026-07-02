# 15. Testing

The test suite is organized in **two tiers**, mirroring the `winter-kernel`
layout: a **default** tier that runs anywhere, and a **container** tier for heavy,
environment-specific checks. Suite selection is driven by PHPUnit `testsuite`
definitions in `phpunit.xml` (the default suite is `base` + `working`).

## Default tier — runs on any machine

`composer test` runs the `base` and `working` suites. Anything requiring an absent
extension (e.g. `ext-shmop`, `swoole`) self-skips, so it is always green on a plain
PHP install.

```bash
composer install
composer test            # base + working (the default suite)
composer test-base       # unit-level class correctness
composer test-working    # end-to-end scenarios
composer test-detail     # testdox (human-readable) output
```

### `base` — class correctness in isolation (`tests/Base`)

Unit-level tests for each building block:

| Test | Covers |
|---|---|
| `Engine/EngineTest` | `AdaptiveEngine` detection/overrides & `ManualEngine` withers + unset-part throws |
| `Launch/CliLauncherTest` | command building, escaping, env, start-failure handling |
| `Launch/ProcessHandleTest` | `reap`/`join`/`detach`/destructor semantics & the non-blocking guarantee |
| `LaunchSpecTest` | the launch DTO defaults/values |
| `Payload/PipeTransportTest`, `TempFileTransportTest`, `ShmTransportTest`, `StagedPayloadTest` | each transport's stage/receive/cleanup (shm self-skips without `ext-shmop`) |
| `Runner/AdaptiveRunnerTest` | receive → deserialize/verify → run, and the failure exits |

### `working` — real end-to-end scenarios (`tests/Working`)

Spawns actual processes and drives them:

| Test | Covers |
|---|---|
| `ThreadTest`, `ThreadFacadeTest` | the full `Thread` API, metadata, double-start guard |
| `TransportScenariosTest` | every transport delivering a real task |
| `SignalTest` | `pause`/`resume`/`terminate`/`kill`/`interrupt` behavior |
| `DetachedTest` | detached start returns at once; worker reparents |
| `PoolLoopTest` | the non-blocking `reap()` harvest loop |
| `FailureModesTest` | fault tolerance: bad binary/runner, double-start, throwing tasks, bad payload |
| `WRunnerTest` | the `wRunner` bootstrap end to end |

## Container tier — heavy & environment-specific

The `container` suite (`tests/Container`) is **excluded from the default run** and
executed inside Docker, where `/proc`, Swoole, `ext-shmop`, and a reaping init are
all available.

```bash
tests/run-container.sh              # default versions: 8.4 8.5
tests/run-container.sh 8.4          # a single version
tests/run-container.sh 8.4 8.5 8.6  # a custom list

# Inside an environment that already has swoole/shmop:
composer test-container             # phpunit --testsuite container
```

What it covers, per PHP version:

| Group | Test | Verifies |
|---|---|---|
| **Leak** | `LeakCliTest` | no zombies, no fd growth, no memory growth across many spawns |
| | `FpmScenarioTest` | the long-lived-parent (FPM) scenario stays clean |
| | `SecurityTest` | payload absent from `/proc/<pid>/cmdline`; unsigned/tampered payloads rejected |
| **Timing** | `OverheadTest` | start-up latency per transport; detached start doesn't block on the task |
| **Swoole** | `SwooleScenariosTest` | payload delivered intact inside a hooked coroutine **and** with the runtime dormant |
| **Payload** | `LargePayloadTest` | a payload far larger than a pipe buffer arrives byte-intact |
| **Load** | `StressTest` | concurrency stress (40 / 100 at once, 300 through a bounded pool) with a printed throughput/fd/zombie summary |
| **Metrics** | `MemoryFootprintTest` | prints a worker RSS report (default build vs a lean `php -n` worker) |
| **Nested** | `NestedThreadTest` | a `Thread` inside a `Thread`, three levels deep |
| **Workload** | `BattleRunTest` | a mixed "battle run" of a dozen heterogeneous jobs against expected results |

`tests/Fixtures` holds the named task classes these suites reuse (payloads must be
serializable named classes to survive the process boundary cleanly).

## The container image

`tests/docker/Dockerfile` builds `php:<version>-cli` with `pcntl`, `posix`,
`shmop`, and `swoole` (plus `procps` for `ps` / `/proc`). The compose file runs the
container with `init: true` so [detached](09-detached-mode.md) workers are reaped
by tini — matching the production guidance for containers.

## Continuous integration

`.github/workflows/ci.yml` runs two jobs across a PHP `8.4` / `8.5` matrix:

- **default** — via `setup-php`; runs code style (`phpcs`) then `composer test`;
- **container** — builds the Docker image (with layer caching) and runs the default
  + container suites inside it (`docker run --init`).

Third-party actions are pinned to commit SHAs for supply-chain safety.

## Code style

```bash
composer cs-check   # phpcs
composer cs-fix     # phpcbf
```
