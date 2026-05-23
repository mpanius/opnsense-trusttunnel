# os-trusttunnel — OPNsense plugin for TrustTunnel VPN

[![License: BSD-2-Clause](https://img.shields.io/badge/license-BSD--2--Clause-blue.svg)](LICENSE)

OPNsense plugin (Server + Client tabs) wrapping the
[TrustTunnel](https://github.com/TrustTunnel/TrustTunnel) VPN. Hosts an
endpoint on one OPNsense router, joins it from another, and routes a LAN
across the tunnel — asymmetric site-to-site in v1, full mesh planned for
v2.

Status: **v1 functional end-to-end** — FreeBSD client `trusttunnel_client`
builds + reaches `VPN_SS_CONNECTED` + forwards real HTTP traffic through
the tunnel (`HTTP 301 in 93 ms` via `curl http://1.1.1.1/` over `tun0`).
See [`docs/freebsd-port-patches.md`](docs/freebsd-port-patches.md) for the
~30 cumulative FreeBSD-portation patches.

See [`docs/`](docs/) for design, release, and patch documentation.

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
