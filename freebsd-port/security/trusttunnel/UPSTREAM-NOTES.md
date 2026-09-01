# `security/trusttunnel` — upstream notes

> Captured **before** writing this port's Makefile, per
> `docs/plans/2026-05-23-opnsense-trusttunnel-plugin.md` (Task 2 Step 0).
> Verifies plan Assumption **A1**.

## Reference port: `net/quiche`

Source of recipes (`USES`, BoringSSL vendoring, `BORING_BSSL_RUST_CPPLIB`,
`-D_XOPEN_SOURCE=700` removal patch):

- URL: <https://raw.githubusercontent.com/freebsd/freebsd-ports/main/net/quiche/Makefile>
- Snapshot taken: 2026-05-23
- `PORTNAME=quiche`, `DISTVERSION=0.24.5`, `PORTREVISION=6`
- BoringSSL pinned at Google commit `e1d6cd95a`, fetched via
  `GH_TUPLE=google:boringssl:e1d6cd95a:boringssl/quiche/deps/boringssl`
- Build env: `MAKE_ENV+= BORING_BSSL_RUST_CPPLIB=c++`
- BoringSSL build flag fix (post-patch):
  `find … | xargs sed -i 's,-D_XOPEN_SOURCE=700,,'` on
  `${WRKSRC}/quiche/deps/boringssl`
- `USES=cargo llvm:build,lib`

## Version reconciliation

**TrustTunnel `Cargo.lock` (upstream tag v1.1.0, commit
`9f11ab2f47d971fd8ba507470a14df2232b2f57d`):**

| Crate | Version |
| --- | --- |
| `quiche` | **0.24.9** |
| `boring` | 4.22.0 |

**`net/quiche` port version**: 0.24.5 (one PATCH behind).

**Decision (per plan Risks fallback):** Vendor BoringSSL ourselves in this
port via `GH_TUPLE` copied from `net/quiche` (same commit `e1d6cd95a`).
Do NOT depend on `net/quiche` transitively — versions diverge by patch
release and dep-resolution risks divergent BoringSSL ABI. We use exactly
the same BoringSSL commit + same `-D_XOPEN_SOURCE=700` patch.

If a future TrustTunnel release bumps `quiche` to a major-incompatible
version (e.g., 0.25.x with breaking BoringSSL ABI), revisit this file
and re-sync with whatever `net/quiche` is at that time. A quiche minor
bump (0.24.x → 0.25.x) is OK as long as the BoringSSL commit Cargo uses
hasn't changed.

## Patches we inherit from net/quiche

1. **`-D_XOPEN_SOURCE=700` removal** — `post-patch` block walks BoringSSL
   tree and strips this define from any file containing it.

   Rationale: FreeBSD's libc behaviour with `_XOPEN_SOURCE=700` defined
   exposes a missing `getentropy` declaration that BoringSSL's
   `boringssl/crypto/rand_extra/rand_extra.c` relies on. Removing the
   define lets `<unistd.h>` expose the symbol.

2. **`RUSTFLAGS` for i386** — `add sse,sse2 target-features only on i386`.
   We carry this verbatim (no harm on amd64).

Текущий v1.1.0 package собран под FreeBSD 15.1; полный набор vendored crates
зафиксирован в `Makefile` и `distinfo`.

## Cargo workspace targets

Server-side port `security/trusttunnel` builds these binaries from the
TrustTunnel Rust workspace:

- `trusttunnel_endpoint` — server daemon (from `endpoint/` crate, binary
  name `trusttunnel_endpoint`)
- `setup_wizard` — installed as `/usr/local/sbin/trusttunnel_setup_wizard`
  to avoid generic-name collisions (from `tools/setup_wizard/` crate)

Client-side port `security/trusttunnel-client` is a **separate port** —
TrustTunnelClient uses CMake + Conan + Rust subcrates, NOT a Cargo
workspace. See its own UPSTREAM-NOTES.md.
