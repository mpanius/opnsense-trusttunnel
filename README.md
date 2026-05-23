# os-trusttunnel — OPNsense plugin for TrustTunnel VPN

[![License: BSD-2-Clause](https://img.shields.io/badge/license-BSD--2--Clause-blue.svg)](LICENSE)

OPNsense plugin (Server + Client tabs) wrapping the
[TrustTunnel](https://github.com/TrustTunnel/TrustTunnel) VPN. Hosts an
endpoint on one OPNsense router, joins it from another, and routes a LAN
across the tunnel — asymmetric site-to-site in v1, full mesh planned for
v2.

Status: **early development (v1 in progress)** — see
[`docs/`](docs/) for design and release docs.

## Features (v1)

- **Server tab** — host a TrustTunnel endpoint: pick a cert from System →
  Trust → Certificates (works with `os-acme-client`-issued certs), define
  users, Apply. Per-user **Export deep-link** with QR code.
- **Client tab** — paste a `tt://?...` deep-link, preview the decoded
  fields as a trust gate, Confirm, Apply. Multi-server array with one
  Active server.
- **HA cluster sync** — `trusttunnel_xmlrpc_sync()` ships config to the
  standby on CARP failover; passwords stripped via `nosync="1"`.
- **Auto-firewall rule** — plugin creates and tracks one inbound pass
  rule on WAN for the chosen listen port. Visible in Firewall → Rules,
  user-editable, marked with `<plugin_managed>os-trusttunnel</plugin_managed>`.
- **Per-user revocation** — drop a user from the Server bootgrid;
  endpoint restarts and existing connections fail on next handshake.

## Compatibility

- OPNsense CE 25.x (FreeBSD 14.x base)
- Companion binaries built from the `security/trusttunnel` and
  `security/trusttunnel-client` FreeBSD ports shipped alongside this
  plugin (`freebsd-port/security/` — built natively on FreeBSD 14)

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
