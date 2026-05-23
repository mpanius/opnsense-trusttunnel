# Installing os-trusttunnel on OPNsense CE 25.x

This plugin is distributed from a self-hosted signed pkg repo. v1
targets OPNsense Community Edition 25.x (FreeBSD 14.x base).

> Future: a PR into `opnsense/plugins` is on the v2 roadmap. Until then,
> follow the steps below.

## Prerequisites

- OPNsense CE 25.x installed and reachable over SSH
- Root shell on the OPNsense box (`pkg install` requires root)

## 1. Trust the signing key

Fetch the public key from this repository and place it where the FreeBSD
pkg subsystem looks for repo signing keys:

```sh
fetch -o /usr/local/etc/ssl/trusttunnel-repo.cert \
    https://raw.githubusercontent.com/mpanius/opnsense-trusttunnel/master/repo-pub.cert
```

## 2. Register the repo

Create `/usr/local/etc/pkg/repos/trusttunnel.conf` with the snippet
below. Substitute the actual host once published — see
`docs/release.md`.

> Until the official release, `${REPO_URL}` is a placeholder. The
> release runbook substitutes it for the public URL of the signed repo
> before tagging.

```ini
trusttunnel: {
    url:            "${REPO_URL}/${ABI}",
    mirror_type:    "srv",
    signature_type: "pubkey",
    pubkey:         "/usr/local/etc/ssl/trusttunnel-repo.cert",
    enabled:        yes
}
```

## 3. Install

```sh
pkg update
pkg install -y os-trusttunnel
```

This pulls in:

- `trusttunnel` — the TrustTunnel endpoint binary (server-side)
- `trusttunnel-client` — the client binary (client-side)
- `qrencode` — for QR PNG export
- `os-trusttunnel` — the OPNsense plugin itself

## 4. Open the GUI

Web UI → **VPN → TrustTunnel**. Two tabs:

- **Server**: enable to host a TrustTunnel endpoint. Pick a cert from
  System → Trust (the plugin can generate a self-signed one for you),
  add users, click Apply. Per-user **Export deeplink** opens a modal
  with the `tt://?...` URI + QR.
- **Client**: paste a `tt://?...` deeplink, review the trust-gate
  preview (hostname, fingerprint, advertised mode, username), click
  Confirm. The imported server becomes Active automatically. Click
  Apply to start the tunnel.

## 5. Site-to-site setup

Follow the OPNsense [WireGuard site-to-site how-to][s2s] structurally —
the plugin's TUN device `tt0` appears in **Interfaces → Assignments**
once the client service is running. Assign it, add NAT/firewall rules
for the remote LAN CIDR. The server side auto-creates an inbound `tcp/443`
Pass rule on WAN (marked `[os-trusttunnel auto]`); review under
**Firewall → Rules → WAN**.

[s2s]: https://docs.opnsense.org/manual/how-tos/wireguard-s2s.html

## Uninstall

```sh
pkg delete os-trusttunnel
```

`+POST_DEINSTALL.post` cleans up:

- Any orphan firewall rules tagged `<plugin_managed>os-trusttunnel</plugin_managed>`
- The runtime directories `/usr/local/etc/trusttunnel` and `/var/log/trusttunnel`

Trust-store certs that you created via the plugin remain in System →
Trust (uninstalling the plugin shouldn't remove your certs).

## Troubleshooting

- **`pkg install` fails with signature mismatch** — verify
  `/usr/local/etc/ssl/trusttunnel-repo.cert` matches the contents of
  `repo-pub.cert` in this repo.
- **Plugin installs but the menu entry is missing** — `configctl
  webgui restart` to reload the menu cache.
- **`trusttunnel_endpoint` won't start** — check
  `/var/log/configd.log` and `/var/log/trusttunnel/`. The Server tab
  shows a status indicator that polls `configctl trusttunnel
  server.status` every 5 s.
- **Deeplink import returns 4xx** — the parser caps URI at 64 KB and
  rejects malformed TLV; check the modal's error message.
