# os-trusttunnel — OPNsense plugins for TrustTunnel VPN

[![License: BSD-2-Clause](https://img.shields.io/badge/license-BSD--2--Clause-blue.svg)](LICENSE)

**Two** OPNsense plugins wrapping the
[TrustTunnel](https://github.com/TrustTunnel/TrustTunnel) VPN, split by
role so a box only pulls the binary it actually needs:

| Plugin | Role | Depends on | Menu |
|--------|------|------------|------|
| `os-trusttunnel` | **Server** — host an endpoint | `trusttunnel` (Rust, builds cleanly) + `qrencode` | VPN → TrustTunnel |
| `os-trusttunnel-client` | **Client** — join an endpoint | `trusttunnel-client` (C++/Rust, ~30 FreeBSD patches) | VPN → TrustTunnel Client |

Install one, the other, or both. A router that only hosts an endpoint
never pulls the fragile client binary; a router that only joins never
pulls the server stack. Asymmetric site-to-site in v2, full mesh later.

Status: **v2 functional end-to-end** — FreeBSD client `trusttunnel_client`
builds + reaches `VPN_SS_CONNECTED` + forwards real traffic through the
tunnel (sustained 114 Mbit/s, 197 Mbit/s single-stream — see
[`docs/bandwidth-benchmark.md`](docs/bandwidth-benchmark.md)).
[`docs/freebsd-port-patches.md`](docs/freebsd-port-patches.md) documents
the ~30 cumulative FreeBSD-portation patches.

See [`docs/`](docs/) for design, release, and patch documentation.

## Repository layout

```
net/os-trusttunnel/         — server plugin (PLUGIN_NAME=trusttunnel)
net/os-trusttunnel-client/  — client plugin (PLUGIN_NAME=trusttunnel-client)
freebsd-port/security/      — the two FreeBSD ports producing the binaries
docs/                       — shared documentation
dist/                       — built .pkg artifacts (also on GitHub Releases)
```

Each `net/<plugin>/` dir is a standard OPNsense plugin (`Makefile` +
`src/`). State is namespaced per plugin: server writes
`<OPNsense><trusttunnel>`, client writes `<OPNsense><trusttunnelclient>`
— no collision on HA-sync or uninstall.

## Features

### Server plugin (`os-trusttunnel`)

- Host a TrustTunnel endpoint: pick a cert from System → Trust →
  Certificates (works with `os-acme-client`-issued certs), define users,
  Apply. Per-user **Export deep-link** with QR code.
- **HA cluster sync** — `trusttunnel_xmlrpc_sync()` ships the
  `<trusttunnel>` subtree to the standby on CARP failover; passwords
  stripped via `nosync="1"`.
- **Auto-firewall rule** — creates and tracks one inbound pass rule on
  WAN for the listen port, marked
  `<plugin_managed>os-trusttunnel</plugin_managed>`.
- **Per-user revocation** — drop a user from the bootgrid; endpoint
  restarts and existing connections fail on next handshake.

### Client plugin (`os-trusttunnel-client`)

- Join an endpoint: paste a `tt://?...` deep-link, preview the decoded
  fields as a trust gate, Confirm, Apply. Multi-server array with one
  Active server.
- **TUN device** — registers the `tt<N>` pattern in Interfaces →
  Assignments via `trusttunnelclient_devices()`.
- **HA cluster sync** — ships the `<trusttunnelclient>` subtree.

## Compatibility

- OPNsense CE 25.x and 26.1.x (FreeBSD 14.x base)
- Server binary (`trusttunnel_endpoint`) — built from
  `security/trusttunnel` FreeBSD port. Upstream FreeBSD support merged in
  TrustTunnel PR #28 (Feb 2026).
- Client binary (`trusttunnel_client`) — built from
  `security/trusttunnel-client` FreeBSD port with ~30 patches applied
  to NativeLibsCommon, DnsLibs, and TrustTunnelClient source. Patches
  documented in [`docs/freebsd-port-patches.md`](docs/freebsd-port-patches.md);
  upstream PRs pending.

## Install

During early development the plugin is distributed from a self-hosted
signed pkg repo (see `docs/install.md` once it lands — TBD until v1
release). Direct install:

```sh
pkg add ./os-trusttunnel-X.Y.Z.pkg
```

## See also

- [TrustTunnel](https://github.com/TrustTunnel/TrustTunnel) — endpoint daemon
- [TrustTunnelClient](https://github.com/TrustTunnel/TrustTunnelClient) — client library + CLI
- [TrustTunnel-Keenetic](https://github.com/artemevsevev/TrustTunnel-Keenetic) — Keenetic router integration (community)

## License

BSD-2-Clause — see [LICENSE](LICENSE).
