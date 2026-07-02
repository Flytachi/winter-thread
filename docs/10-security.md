# 10. Security

Winter Thread moves a **serialized** object from the parent into a worker, where
it is deserialized and run. Deserialization of untrusted data is the classic
vector for PHP object injection, so the engine is built to make the delivery
trustworthy. This chapter states the threat model precisely and tells you exactly
what is and isn't protected.

## The trust model

Two facts bound the risk out of the box:

1. **The payload is produced by your own parent process.** The child only ever
   deserializes what your parent serialized and delivered over a **private
   channel**:
   - `PipeTransport` — a parent→child stdin pipe (not on disk, not shared);
   - `TempFileTransport` — a `0600` temp file, readable only by the same OS user,
     unlinked the instant the child starts;
   - `ShmTransport` — a `0600` System V shared-memory segment, readable only by
     the same OS user, deleted on read.

   External/user input never reaches the deserializer unless *you* put it in the
   task.
2. **Deserialization goes through `opis/closure` only.** The engine **never** calls
   native `unserialize()`. That alone removes the classic native-`unserialize`
   gadget-chain surface. `opis/closure` is a hard dependency and additionally
   supports **signed** payloads.

## Signing (recommended in production)

Set a **secret** and every payload is HMAC-signed by the parent and verified by
the child before any object is constructed. A forged or tampered payload — or one
signed with a different secret — is **rejected**: the worker writes a
deserialization error to STDERR, exits non-zero, and **runs nothing**.

```php
use Flytachi\Winter\Thread\Engine\AdaptiveEngine;

// Explicit:
Thread::bindEngine(new AdaptiveEngine(secret: 'a-long-random-secret'));

// Or via the environment (picked up automatically by AdaptiveEngine):
//   WINTER_THREAD_SECRET=a-long-random-secret
```

With `ManualEngine`:

```php
(new ManualEngine())->withSecurity('a-long-random-secret') /* … + other parts */;
```

Under the hood the secret builds an `Opis\Closure\Security\DefaultSecurityProvider`
(returned by `Engine::security()`); the parent signs with it in
`Thread::serialize()`, and the child verifies with it in the runner. When the
verification fails, the runner catches the resulting exception (including Opis's
`SecurityException`) and returns a non-zero exit code — a clean rejection, never a
fatal.

### How the secret reaches the worker

The worker is a **separate process** that reconstructs its own
`new AdaptiveEngine()` (see [7. The Engine](07-the-engine.md)), so it needs the
same secret to verify the signature. The built-in `CliLauncher` passes it through
the child's **environment** (`WINTER_THREAD_SECRET`), **never through argv**. This
distinction is deliberate and matters:

- `/proc/<pid>/cmdline` (argv) is **world-readable** — a secret there would leak to
  any local user running `ps`;
- `/proc/<pid>/environ` is readable **only by the owning user**.

So the signing secret is never exposed in `ps`/argv. This env-based propagation is
**load-bearing**, not a convenience: it is how the parent's secret reaches the
child's independently-constructed engine. (It's also why the secret can't be
"auto-generated and derived on both sides" — anything the child could regenerate
without receiving, an attacker could regenerate too, defeating the signature.)

> If you write a **custom launcher** (SSH/Docker/remote), you must forward
> `WINTER_THREAD_SECRET` into the remote environment yourself — and keep it out of
> any command line or log — or the remote worker won't be able to verify.

## What signing does and doesn't protect

- ✅ **Integrity/authenticity of the payload.** An attacker who can write to the
  transport channel (e.g. tamper the temp file in its brief window, or influence a
  custom transport) cannot forge a payload that verifies — it is rejected before
  construction.
- ✅ **Object-injection defense.** No attacker-controlled object graph is ever
  instantiated, because verification precedes deserialization.
- ❌ **It is not encryption.** The payload is signed, not encrypted; on a private
  owner-only channel that is the right trade-off, but don't put plaintext secrets
  in the task expecting confidentiality from signing.
- ❌ **It doesn't sanitize your own inputs.** If *you* serialize attacker-controlled
  data into the task's properties and then trust it in `run()`, that's an
  application bug signing can't catch.

### The unsigned default

With **no** secret, the payload is still serialized/deserialized through
`opis/closure` (never native `unserialize`), but without cryptographic integrity.
That is acceptable when you fully trust the local channel (default pipe/temp-file/
shm are all owner-only), and it keeps the zero-config path frictionless. **Turn
signing on in production**, especially if a transport could ever be influenced by
another user or process.

## The payload is never on the command line

Only a small, fixed set of **safe flags** is ever placed on the command line:
`--namespace`, `--name`, `--tag`, `--debug`, `--detach`, per-run `--arg-*` values,
and — for shared memory — `--shmkey=<int>`. The serialized task itself **always**
travels through the payload transport (pipe / file / shm), never argv, so task
contents can't leak via `ps`.

Every component of the command — the binary path, the runner path, the namespace/
name/tag, each argument key and value, and any transport CLI arg — is escaped with
`escapeshellarg()` before it reaches `proc_open`'s shell. A transport cannot inject
into the shell command, and a hostile argument value cannot break out of its
quoting.

## Recommendations

- **Set a secret in production** (`WINTER_THREAD_SECRET` or `withSecurity()`) —
  long and random. This upgrades the model from "trust the private channel" to
  "cryptographically verify every payload."
- **Use the same secret in the parent and everywhere `wRunner` runs.** Locally the
  `CliLauncher` handles this; for custom/remote launchers, propagate it via env.
- **Keep tasks serializable and self-contained** — open resources inside `run()`,
  and don't smuggle credentials through task properties that end up in the payload.
- **Restrict `proc_open`** to the contexts where you actually spawn processes (via
  `disable_functions` elsewhere), so the ability to launch workers is scoped.
