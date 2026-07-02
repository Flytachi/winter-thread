# 8. Payload Transports

To run your task in another process, the engine must move the serialized
`Runnable` from the parent to the child. That delivery is a pluggable
**transport**. Three ship with the library, all interchangeable — your task code
never knows which was used, and all three deliver the **exact same bytes**.

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
child treats them identically. This options-driven receive is why the engine never
needs to serialize the transport choice itself — parent and child stay consistent
automatically.

## Choosing a transport

Bind an engine with the transport you want:

```php
use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Payload\TempFileTransport;

Thread::bindEngine(new AdaptiveEngine(transport: new TempFileTransport()));
```

In normal CLI you rarely need to — the default pipe transport is fine. Pick a
different one when:

- **You run under Swoole** — the `AdaptiveEngine` already switches to TempFile
  automatically (below); you only override if you specifically want Shm.
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

Under **Swoole** with `SWOOLE_HOOK_ALL`, the runtime intercepts stream functions.
Pipe file descriptors created by `proc_open` get captured into Swoole's internal
table, which later causes `Bad file descriptor` errors. The fix is to **not use
pipe fds for the payload** — i.e. use TempFile or Shm.

The `AdaptiveEngine` handles this for you: it switches to `TempFileTransport`
automatically when it detects an active Swoole runtime — either inside a coroutine
(`\Swoole\Coroutine::getCid() !== -1`) or with runtime hooks enabled
(`\Swoole\Runtime::getHookFlags() !== 0`). No configuration required:

```php
// Under an active Swoole runtime, this transparently uses TempFile:
$thread = new Thread(new MyTask());
$thread->start();
```

Two caveats remain under Swoole:

1. **Output pipes.** The transport fix covers the *payload* (fd 0). If you start
   with `outputTarget: null`, the *output* pipes (fd 1/2) are subject to the same
   corruption. Prefer **file output** (a path, or `/dev/null`) under Swoole rather
   than `null`. See [5. Output & Debugging](05-output-and-debugging.md).
2. **Dispatch from a coroutine.** Swoole also hooks `proc_open` itself
   (`SWOOLE_HOOK_PROC`), which requires a coroutine context. Launch tasks from
   inside a coroutine — the normal case in a Swoole app.

> Detection is guarded by `extension_loaded('swoole')`, so none of this touches a
> non-Swoole app: without the extension the engine always uses Pipe. And note the
> switch keys on an **active** runtime — merely having the extension installed but
> dormant does not force TempFile.

## How a transport works (internally)

A transport is two cooperating halves across two processes, plus a parent-side
cleanup:

- **Parent — `stage($payload): StagedPayload`** — prepares delivery and returns a
  [`StagedPayload`](../src/Payload/StagedPayload.php) describing the fd-0
  descriptor (`stdinSpec`), any extra CLI args (e.g. `--shmkey=123`), an optional
  `pipePayload` to write after launch (pipe transport), an optional
  `unlinkAfterOpen` path (temp-file transport), and an opaque `ref` used for
  cleanup.
- **Child — `receive($options): string`** — reads the payload back (from STDIN, or
  from the shm segment named by `--shmkey`) and returns the serialized bytes.
- **Parent — `cleanup($staged): void`** — releases the temp file or shm segment.
  It is a **fallback**: normally the child already consumed/deleted the resource
  (temp file unlinked right after launch, shm deleted on read), and `cleanup()`
  runs when the handle finishes to catch the case where the child never got that
  far. It is always safe to call, even if the resource is already gone.

The launcher reads the `StagedPayload` **generically** — it doesn't know which
transport produced it — which is what keeps transports fully pluggable. See
[11. Architecture](11-architecture.md).

## Writing your own transport

Implement [`PayloadTransport`](../src/Payload/PayloadTransport.php) (three methods:
`stage`, `receive`, `cleanup`) and bind it via an engine — nothing else changes.
Ideas: a Redis key, a TCP socket, or a named FIFO. Keep two things in mind:

- **`stage()` and `receive()` run in different processes**, so they can only
  coordinate through what the `StagedPayload` carries onto the command line
  (CLI args) or through an out-of-band channel both sides can name.
- Keep the delivery channel **private** (owner-only) — the payload is a serialized
  object and is the deserialization trust boundary. See [10. Security](10-security.md).
