# 8. Payload Transports

To run your task in another process, the launcher must move the serialized
`Runnable` from the parent to the child. That delivery is a pluggable
**transport** — a parent-side staging strategy. Three ship with the library, all
interchangeable — your task code never knows which was used, and all three deliver
the **exact same bytes**.

## The three transports

| Transport | Delivery | Parent pipe fd? | On child's stdin | Extension |
|---|---|---|---|---|
| [`PipeTransport`](../src/Payload/PipeTransport.php) | serialized task written to the child's stdin **pipe** after launch | **yes** | the pipe | — |
| [`TempFileTransport`](../src/Payload/TempFileTransport.php) | task written to a `0600` **temp file** placed on stdin, unlinked right after launch | **none** | the temp file | — |
| [`ShmTransport`](../src/Payload/ShmTransport.php) | task placed in **System V shared memory**, key passed via `--shmkey` | **none** | `/dev/null` | `ext-shmop` |

- **Pipe** — the default in plain CLI: simplest, nothing on disk, no extra
  extension. The parent writes the payload into the pipe *after* `proc_open` and
  closes it; the child reads its STDIN to EOF.
- **TempFile** — avoids pipe file descriptors entirely (the key property under
  Swoole). The file is created `0600` (owner-only) in the system temp dir and
  **unlinked immediately after the process starts** — the child keeps its open fd,
  so nothing lingers on disk even though the path is gone. No extension needed.
- **Shm** — also avoids pipes, keeping the payload in **RAM only**. The parent
  allocates a `0600` segment, writes the payload, and passes the integer key as
  `--shmkey`; the child reads the segment and **deletes it**. Needs `ext-shmop`;
  if the extension is missing it throws a clear `ThreadException` (on both the
  staging and receiving side) rather than a fatal error.

### How the child chooses its receiving side

The parent's chosen transport determines *staging*, but the **child** picks how to
*receive* purely from CLI options: if `--shmkey` is present it reads shared memory,
otherwise it reads STDIN. Because Pipe and TempFile both arrive on STDIN, the
child treats them identically. This options-driven receive is why the transport is
a **parent-only** concern with no child-side half — parent and child stay
consistent automatically, without shipping the transport choice across the boundary.

## Choosing a transport

Bind a launcher with the transport you want:

```php
use Flytachi\Winter\Thread\Launch\CliLauncher;
use Flytachi\Winter\Thread\Payload\TempFileTransport;

Thread::bindLauncher(CliLauncher::adaptive(transport: new TempFileTransport()));
```

In normal CLI you rarely need to — the default pipe transport is fine (and when
you leave the transport unset, it is auto-detected per launch). Pick a specific
one when:

- **You want nothing on disk, ever** — use `ShmTransport` (RAM only). Requires
  `ext-shmop`.
- **Your `/tmp` is unusual** (tiny tmpfs, noexec, restricted `open_basedir`) —
  Pipe or Shm avoid the temp file.

### Trade-offs at a glance

| Concern | Pipe | TempFile | Shm |
|---|---|---|---|
| Extra extension | none | none | `ext-shmop` |
| Touches disk | no | briefly (unlinked at once) | no |
| Uses pipe fds | yes | no | no |
| Swoole-safe payload | no | yes | yes |
| Large payloads | streamed through the pipe buffer | file-sized | one contiguous segment sized to the payload |

All three are correct for large payloads (a
[dedicated test](15-testing.md) delivers a payload far larger than a pipe buffer
byte-for-byte); they differ only in mechanism and prerequisites.

## Swoole / event-loop compatibility

When it leaves the transport unset, `CliLauncher::adaptive()` picks a **pipe-free**
transport (`TempFileTransport`) if it detects an active Swoole runtime — inside a
coroutine (`\Swoole\Coroutine::getCid() !== -1`) or with runtime hooks enabled
(`\Swoole\Runtime::getHookFlags() !== 0`). The rationale: under `SWOOLE_HOOK_ALL`
the runtime intercepts stream functions, and pipe file descriptors from
`proc_open` do not survive that intact. Detection is guarded by
`extension_loaded('swoole')`, so a non-Swoole app is never affected, and it keys
on an **active** runtime — a dormant extension does not force TempFile.

> **Status: Swoole support is under active development.** Choosing a pipe-free
> transport is necessary but **not sufficient** for running winter-thread from
> *inside a live Swoole coroutine worker*: native `proc_open` contends with the
> Swoole reactor over the process file-descriptor table, and spawning a subprocess
> from a hooked coroutine has limitations that no transport choice resolves on its
> own. Treat in-coroutine dispatch as experimental for now. A robust, documented
> pattern (spawning from outside the reactor) is being worked on — this section
> will be updated when it lands. Plain CLI and FPM are unaffected.

## How a transport works (internally)

A transport is a **parent-only** strategy — `stage()` prepares delivery, `cleanup()`
releases it. There is no child-side method: the child reads the payload itself
(STDIN, or the shm segment named by `--shmkey`) inside the
[`AdaptiveRunner`](11-architecture.md).

- **`stage($payload): StagedPayload`** — prepares delivery and returns a
  [`StagedPayload`](../src/Payload/StagedPayload.php) describing the fd-0
  descriptor (`stdinSpec`), any extra CLI args (e.g. `--shmkey=123`), an optional
  `pipePayload` to write after launch (pipe transport), an optional
  `unlinkAfterOpen` path (temp-file transport), and an opaque `ref` used for
  cleanup.
- **`cleanup($staged): void`** — releases the temp file or shm segment. It is a
  **fallback**: normally the resource is already consumed (temp file unlinked
  after launch, shm deleted by the child on read), and `cleanup()` runs when the
  handle finishes to catch the case where the child never got that far. It is
  always safe to call, even if the resource is already gone.

The launcher reads the `StagedPayload` **generically** — it doesn't know which
transport produced it — which keeps the **parent side** fully generic. See
[11. Architecture](11-architecture.md).

## Writing your own transport

Implement [`PayloadTransport`](../src/Payload/PayloadTransport.php) — `stage` and
`cleanup` — and bind a launcher that uses it. **But mind the child side:** the
default [`AdaptiveRunner`](14-api-reference.md#adaptiverunner-readonly-class)
reads the payload from **STDIN** (or shared memory when `--shmkey` is present). So
two cases differ sharply:

- **Delivering on the child's stdin (fd 0)** — a different way of putting bytes on
  stdin (encryption, compression, a different temp location). This works with the
  stock runner: your `stage()` sets the fd-0 descriptor and the child reads STDIN
  as usual — **no child-side change needed**. (This is exactly how the built-in
  pipe and temp-file transports both work.)
- **Delivering out-of-band** — a Redis key, a TCP socket, a named FIFO: anything
  *not* on stdin/shm. Here the child must be taught how to read it: subclass
  `AdaptiveRunner` and override its protected `receive()` (delegating to
  `parent::receive()` for the built-in cases), or write your own `Runner`, and
  point a [custom `Launcher`](14-api-reference.md#launcher-interface) at a
  bootstrap that uses it. Launcher (parent) and Runner (child) are independent by
  design; see [11. Architecture](11-architecture.md).

Two more things to keep in mind:

- **`stage()` runs in the parent; the child reads independently** — they
  coordinate only through the `StagedPayload`'s CLI args or a channel both sides
  can name.
- Keep the delivery channel **private** (owner-only) — the payload is the
  deserialization trust boundary. See [10. Security](10-security.md).
