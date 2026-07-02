# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 2.x     | ✅ |
| < 2.0   | ❌ |

Security fixes land on the latest `2.x` line.

## Reporting a vulnerability

**Please do not open a public issue for security reports.**

Report privately through GitHub's **[Security Advisories](https://github.com/flytachi/winter-thread/security/advisories/new)**
("Report a vulnerability" on the repository's *Security* tab). Include:

- affected version(s) and PHP version / OS;
- a description and, ideally, a minimal reproduction;
- the impact you believe it has.

You can expect an acknowledgement within a few days. Once a fix is ready, a
patched release is published and the advisory is disclosed with credit (unless you
prefer to remain anonymous).

## Security model (what the library guarantees, and what it doesn't)

Winter Thread serializes a task in the parent and deserializes it in a worker
process, so its threat model centers on **payload integrity**. See
[docs/10 — Security](docs/10-security.md); in short:

- **Deserialization is always via `opis/closure`**, never native `unserialize()`.
- **Signing is opt-in.** Set a secret (`WINTER_THREAD_SECRET` env, or
  `withSecurity()`) and every payload is HMAC-signed by the parent and verified by
  the child; forged/tampered payloads are rejected before any object is built. With
  **no** secret, the payload is unsigned and the trust boundary is the private,
  owner-only delivery channel (stdin pipe, `0600` temp file, or `0600` shared
  memory).
- **The signing secret travels only in the environment** (`WINTER_THREAD_SECRET`,
  owner-readable `/proc/<pid>/environ`), **never on argv**.
- **The serialized task never appears on the command line.** Only safe flags do
  (`--namespace`, `--name`, `--tag`, `--debug`, `--detach`, `--arg-*`, `--shmkey`),
  each `escapeshellarg()`-escaped.

### Your responsibilities

- **Set a secret in production** (`WINTER_THREAD_SECRET` or `withSecurity()`) — long
  and random — so payloads are cryptographically verified.
- **Never put secrets in `start()` arguments or in the namespace/name/tag** — those
  are visible via `ps` / `/proc/<pid>/cmdline` to same-user processes. Put sensitive
  data in the task's **constructor** (it rides in the payload, not argv).
- **Scope `proc_open`** (via `disable_functions` elsewhere) to where you intend to
  spawn processes.
- The `0600` shared-memory segment blocks other users, but same-uid processes can
  attach via the key on argv — treat same-uid as a trust boundary.

Full details and rationale: **[docs/10-security](docs/10-security.md)** and the
[documentation index](docs/README.md).
