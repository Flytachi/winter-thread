# 7. Payload Transports

To run your task in another process, the engine must move the serialized
`Runnable` from the parent to the child. That delivery is a pluggable
**transport**. Three ship with the library, all interchangeable.

## The three transports

| Transport | Delivery | Parent pipe fd | Extension |
|---|---|---|---|
| [`PipeTransport`](../src/Payload/PipeTransport.php) | serialized task written to the child's stdin **pipe** | yes | — |
| [`TempFileTransport`](../src/Payload/TempFileTransport.php) | task written to a `0600` **temp file** placed on stdin, unlinked right after launch | **none** | — |
| [`ShmTransport`](../src/Payload/ShmTransport.php) | task placed in **System V shared memory**, key passed via `--shmkey` | **none** | `ext-shmop` |

- **Pipe** is the default in plain CLI: simplest, no temp files, no extra
  extension.
- **TempFile** avoids pipe file descriptors entirely — the key property under
  Swoole (below). No extension needed.
- **Shm** also avoids pipes, keeping the payload in RAM; needs `ext-shmop`. If the
  extension is missing it throws a clear `ThreadException` rather than a fatal.

All three deliver the exact same bytes; they differ only in *how*. Your task code
never knows which was used.

## Choosing a transport

Bind an engine with the transport you want:

```php
use Flytachi\Winter\Thread\Engine\AdaptiveEngine;
use Flytachi\Winter\Thread\Payload\TempFileTransport;

Thread::bindEngine(new AdaptiveEngine(transport: new TempFileTransport()));
```

In normal CLI you rarely need to: the default pipe transport is fine.

## Swoole / event-loop compatibility

Under **Swoole** with `SWOOLE_HOOK_ALL`, the runtime intercepts stream functions.
Pipe file descriptors created by `proc_open` get captured into Swoole's internal
table, which later causes `Bad file descriptor` errors. The fix is to not use
pipe fds for the payload — i.e. use **TempFile** or **Shm**.

The `AdaptiveEngine` handles this for you: it switches to `TempFileTransport`
automatically when it detects an active Swoole runtime — either inside a
coroutine (`\Swoole\Coroutine::getCid() !== -1`) or with runtime hooks enabled
(`\Swoole\Runtime::getHookFlags() !== 0`). No configuration required.

```php
// Under Swoole, this transparently uses TempFile:
$thread = new Thread(new MyTask());
$thread->start();
```

Two caveats under Swoole:

1. **Output pipes.** The transport fix covers the *payload* (fd 0). If you set
   `outputTarget: null`, the *output* pipes (fd 1/2) are subject to the same
   corruption. Prefer file output (`/dev/null` or a path) under Swoole.
2. **Dispatch from a coroutine.** Swoole also hooks `proc_open` itself
   (`SWOOLE_HOOK_PROC`), which requires a coroutine context. Launch tasks from
   inside a coroutine, which is the normal case in a Swoole app.

## How a transport works (internally)

A transport is two cooperating halves across two processes:

- **Parent — `stage($payload)`**: prepares delivery and returns a
  [`StagedPayload`](../src/Payload/StagedPayload.php) describing the fd-0
  descriptor and any extra CLI args (e.g. `--shmkey=123`).
- **Child — `receive($options)`**: reads the payload back (from STDIN, or from the
  shm segment named by `--shmkey`).
- **Parent — `cleanup($staged)`**: releases the temp file or shm segment as a
  fallback if the child didn't.

Writing your own transport (e.g. Redis, a TCP socket) means implementing
[`PayloadTransport`](../src/Payload/PayloadTransport.php) and binding it via an
engine — nothing else changes.
