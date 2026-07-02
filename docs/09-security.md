# 9. Security

Winter Thread moves a **serialized** object from the parent into a worker, where
it is deserialized and run. Deserialization of untrusted data is the classic
vector for PHP object injection, so the engine is built to make the delivery
trustworthy.

## The trust model

Two facts bound the risk out of the box:

1. **The payload is produced by your own parent process.** The child only ever
   deserializes what your parent serialized and delivered over a private channel:
   a parent→child stdin pipe/temp-file, or a `0600` shared-memory segment (readable
   only by the same OS user). External input never reaches the deserializer.
2. **Deserialization goes through `opis/closure` only.** The engine never calls
   native `unserialize()`. `opis/closure` is a hard dependency, and it supports
   **signed** payloads.

## Signing (recommended in production)

Set a **secret** and every payload is HMAC-signed by the parent and verified by
the child. A forged or tampered payload is rejected before any object is
constructed — the worker exits non-zero and runs nothing.

```php
use Flytachi\Winter\Thread\Engine\AdaptiveEngine;

// Explicit:
Thread::bindEngine(new AdaptiveEngine(secret: 'a-long-random-secret'));

// Or via the environment (picked up automatically):
//   WINTER_THREAD_SECRET=a-long-random-secret
```

With `ManualEngine`:

```php
(new ManualEngine())->withSecurity('a-long-random-secret') /* … */;
```

### How the secret reaches the worker

The worker is a separate process, so it needs the same secret to verify the
signature. The launcher passes it through the child's **environment**
(`proc_open`'s env), **never through argv**. This matters:

- `/proc/<pid>/cmdline` (argv) is world-readable — a secret there leaks to any
  local user;
- `/proc/<pid>/environ` is readable only by the owning user.

So the signing secret is never exposed in `ps`/argv.

## Payload is never in the command line

Only a small, safe set of flags is ever placed on the command line
(`--namespace`, `--name`, `--tag`, `--debug`, `--detach`, and for shared memory a
`--shmkey=<int>`). The serialized task itself always travels through the payload
transport (pipe / file / shm) — never argv — so task contents can't leak via
`ps`. Every command component is escaped with `escapeshellarg()`.

## Recommendations

- **Set a secret in production** (`WINTER_THREAD_SECRET` or `withSecurity()`).
  This upgrades the model from "trust the private channel" to "cryptographically
  verify every payload", which matters if a transport could ever be influenced by
  an attacker.
- Keep tasks **serializable and self-contained** — open resources inside `run()`,
  don't smuggle secrets through task properties that end up in the payload.
- Ensure `proc_open` is only enabled where you intend to spawn processes.
