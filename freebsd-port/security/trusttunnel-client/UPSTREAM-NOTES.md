# `security/trusttunnel-client` — upstream notes

> Step 0 discovery, captured alongside `security/trusttunnel`.

## Source

- Repo: <https://github.com/TrustTunnel/TrustTunnelClient>
- Latest tag: `v1.1.4`
- Build system: **CMake 3.24+ + Conan 2.0.5+ + Ninja** (NOT a Cargo workspace)
- Languages: C++20 + C + Rust 1.85 (three subcrates inside `trusttunnel/`:
  `settings/`, `setup_wizard/`, `deeplink-ffi/`)

## Reference for FreeBSD ports of CMake + Conan projects

`net/quiche` does not apply here — it's pure Cargo. Closer references:

- `net/wireguard-go` (Go CMake-less) — no
- `security/age` (Rust simple) — no
- TrustTunnelClient is sufficiently unusual that we author this port
  greenfield, following standard `USES=cmake:noninja` (or `cmake` if
  Ninja is preferred) + an explicit `do-build` that drives `make` from
  the project's own top-level Makefile (which handles Conan bootstrap
  + CMake configure + build internally per `~/code/TrustTunnelClient/Makefile`).

## Build approach (greenfield port)

The upstream project ships a top-level `Makefile` that already does the
right thing on a developer machine:

```
make init           # one-time hooks
make bootstrap_deps # exports AdGuard Conan recipes to local cache
make all            # bootstrap + cmake + build client + setup_wizard
```

On the build VM we run `make all` once, then collect the resulting
binaries from `build/trusttunnel/trusttunnel_client` and
`build/trusttunnel/setup_wizard/trusttunnel_setup_wizard`.

**Caveat:** Conan recipes require network access during build, which
contradicts `bsd.port.mk`'s default `NO_FETCH`-style policy. Practical
solutions:

1. Pre-populate Conan cache before `make package` (developer runs
   `make bootstrap_deps` once outside the port, then port build skips
   bootstrap via `SKIP_BOOTSTRAP=1`). This is what we'll do for v1.
2. Future: write a true FreeBSD port using `USES=cmake conan` once
   FreeBSD ports tree adds Conan 2 support.

For v1: this port assumes the build VM has Conan recipes already cached
(documented in `~/code/opnsense-trusttunnel/freebsd-port/README.md`).

## Conan deps

The TrustTunnelClient `conanfile.py` requires AdGuard private recipes:

- `dns-libs/2.8.51@adguard/oss`
- `native_libs_common/8.1.28@adguard/oss`
- `klib/2021-04-06@adguard/oss`
- `ldns/2021-03-29@adguard/oss`
- `libevent/2.1.11@adguard/oss`
- `nghttp2/1.56.0@adguard/oss`
- `quiche/0.17.1@adguard/oss` — note: different from server-side
  (uses AdGuard's fork of quiche, not Cloudflare's mainline 0.24.x)
- `openssl/boring-2024-09-13@adguard/oss`

These are exported via `scripts/bootstrap_conan_deps.py` in the
TrustTunnelClient repo. Conan profiles in `conan/` directory.

## Binaries produced

- `trusttunnel_client` → `/usr/local/sbin/trusttunnel_client`
- `trusttunnel_setup_wizard` (client-side wizard, different binary from
  server's `setup_wizard`) → installed under same name in client port,
  collision avoided via separate `${PREFIX}/sbin/trusttunnel_client_setup_wizard`

For v1 we only install `trusttunnel_client` — the client wizard is not
used by the os-trusttunnel plugin (plugin renders config from PHP).
