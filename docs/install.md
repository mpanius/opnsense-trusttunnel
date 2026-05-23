# Installing os-trusttunnel on OPNsense

v1 targets OPNsense Community Edition 25.x and 26.1.x (FreeBSD 14.x base).
Two installation paths:

1. **Direct from GitHub Releases** — copy the prebuilt `.pkg` and binaries
   onto your OPNsense. Simplest for one-host setup.
2. **From a signed pkg repository** — point OPNsense at a repo URL so future
   updates flow automatically. Recommended for production / fleets.

> Future: a PR into `opnsense/plugins` is on the roadmap. Until then,
> follow one of the paths below.

## Prerequisites

- OPNsense CE 25.x or 26.1.x installed and reachable over SSH
- Root shell on the OPNsense box (`pkg add` requires root)
- About 200 MB free on `/` (binaries are ~100 MB combined)

---

## Path A — direct from GitHub Releases (recommended for testing)

Tags v1.0.x ship a `.pkg` + binary tarball as release attachments.

```sh
# On the OPNsense host
cd /tmp
fetch -o os-trusttunnel-1.0.1.pkg \
    https://github.com/mpanius/opnsense-trusttunnel/releases/download/v1.0.1/os-trusttunnel-1.0.1.pkg
fetch -o trusttunnel-binaries-freebsd14-amd64.tar.gz \
    https://github.com/mpanius/opnsense-trusttunnel/releases/download/v1.0.1/trusttunnel-binaries-freebsd14-amd64.tar.gz

# Install plugin (PHP/Volt MVC + configd actions + rc.d scripts)
pkg add -f ./os-trusttunnel-1.0.1.pkg

# Install the FreeBSD binaries
tar xzf trusttunnel-binaries-freebsd14-amd64.tar.gz -C /usr/local/bin

# Server-side endpoint binary is shipped separately by `security/trusttunnel`;
# until that port is in the official FreeBSD ports tree, build it on a
# FreeBSD 14 VM (see docs/release.md) and copy the resulting
# trusttunnel_endpoint into /usr/local/sbin/.

# Re-render OPNsense menus + ACL + Web UI
configctl webgui restart
```

Open the web UI → **VPN → TrustTunnel**. See step 4 below for the rest.

---

## Path B — signed pkg repository (recommended for production)

This routes plugin updates through `pkg update` like any other OPNsense
package. Requires the public signing key + a repo definition.

### B1. Trust the signing key

```sh
fetch -o /usr/local/etc/ssl/trusttunnel-repo.cert \
    https://raw.githubusercontent.com/mpanius/opnsense-trusttunnel/master/repo-pub.cert
```

> The repo public key is committed at the repo root. The matching
> private key is stored offline in 1Password — see `docs/release.md`
> for the maintainer key-management procedure.

### B2. Register the repo

Create `/usr/local/etc/pkg/repos/trusttunnel.conf`. Substitute
`${REPO_URL}` with the host that serves the signed pkg repo. For
self-hosted deployments, point at your own static HTTPS dir.

```ini
trusttunnel: {
    url:            "${REPO_URL}/${ABI}",
    mirror_type:    "srv",
    signature_type: "pubkey",
    pubkey:         "/usr/local/etc/ssl/trusttunnel-repo.cert",
    enabled:        yes
}
```

### B3. Install

```sh
pkg update
pkg install -y os-trusttunnel
```

`pkg` resolves dependencies:

- `os-trusttunnel` — the OPNsense plugin (PHP/Volt + configd + rc.d)
- `trusttunnel` — server-side endpoint binary
- `trusttunnel-client` — client binary + setup_wizard
- `qrencode` — used for QR PNG export of deeplinks

---

## 4. First-run setup

Web UI → **VPN → TrustTunnel**. Two tabs:

### Server tab (host an endpoint)

1. Tick **Enable Server**.
2. **Listen address** — `0.0.0.0:443` is the default. Note: this conflicts
   with the OPNsense Web UI on stock setups. Either:
   - Move the Web UI to a different port (`System → Settings →
     Administration → TCP port = 8443`), or
   - Bind the endpoint to a specific WAN IP (e.g. `203.0.113.10:443`)
     leaving the Web UI on `0.0.0.0:443`.
3. **Hostname** — must match the cert CN (or SAN). Example: `vpn.example.com`.
4. **TLS certificate** — pick from System → Trust → Certificates. If you
   don't have one, click **Generate self-signed cert** (rcgen via PHP
   openssl pipeline; immediately appears in the dropdown).
5. **Protocols** — leave `http2, http3` checked unless you have a reason
   to disable one. The plugin renders them as
   `[listen_protocols.http2]` + `[listen_protocols.quic]` in `vpn.toml`.
6. Add a user under **Users** → click **+** → username + password → Save.
7. Click **Apply**. The plugin runs the `configd` action chain:
   - `materialize_certs.php` — write cert/key PEMs from the Trust store
     to `/usr/local/etc/trusttunnel/server/certs/`.
   - `render_server_config.py` — re-generate `vpn.toml`, `hosts.toml`,
     `credentials.toml`, `rules.toml`.
   - `/usr/local/etc/rc.d/trusttunnel_endpoint onerestart` — restart the
     daemon.
8. **Export deeplink** — row action in the Users bootgrid. Modal shows
   both the `tt://?...` URI and a QR PNG. Distribute to the client side
   out-of-band.

### Client tab (join an endpoint)

1. Click **Import deeplink** → paste the `tt://?...` URI from the server.
2. Trust gate preview shows hostname, fingerprint_sha256, advertised
   mode, username. Click **Confirm Import** — the parsed server is
   written into the `<client><servers>` array.
3. Select the imported row → set as **Active** (one server active at a
   time).
4. **TUN interface** — default `tt0`. Letters/digits/underscore, must
   start with a letter, ≤ 15 chars.
5. **Mode** — `general` (route everything via tunnel except the
   excluded routes), or `selective` (route only allowed destinations).
6. Click **Apply**. The plugin runs `client.reconfigure`:
   - `render_client_config.py` writes `trusttunnel_client.toml`.
   - `trusttunnel_client` restarts and creates the TUN device.

## 5. Site-to-site setup

Once the client service is running, the TUN device (default `tt0`)
appears in **Interfaces → Assignments**. Follow the OPNsense
[WireGuard site-to-site how-to][s2s] structurally:

1. **Interfaces → Assignments** — add a new interface backed by `tt0`,
   give it a friendly name (e.g. `TT0`). Save.
2. **Interfaces → [TT0]** — enable, no IPv4/IPv6 config (the
   trusttunnel_client sets these up).
3. **Firewall → NAT → Outbound** — add a manual rule translating the
   remote LAN CIDR to the TT0 interface address (Hybrid mode is
   simplest).
4. **Firewall → Rules → TT0** — add a pass rule for the remote LAN
   CIDR if you want to expose this side's network to the remote side
   (asymmetric: only one side advertises in v1).
5. The server side auto-creates an inbound `tcp/udp 443` Pass rule on
   WAN (marked `<plugin_managed>os-trusttunnel</plugin_managed>` in
   `config.xml`). Review under **Firewall → Rules → WAN**.

[s2s]: https://docs.opnsense.org/manual/how-tos/wireguard-s2s.html

## 6. Verify the tunnel

On the client OPNsense, in a root shell:

```sh
# Tunnel established?
service trusttunnel_client onestatus
ifconfig tt0  # should be UP, POINTOPOINT, RUNNING, with inet 172.16.219.2 -> ...

# Real data flow?
route add -host 1.1.1.1 -interface tt0
curl -sk -o /dev/null -w "%{http_code} %{time_total}s %{speed_download} B/s\n" \
     http://cachefly.cachefly.net/10mb.test
route delete -host 1.1.1.1
```

Expect ~80+ Mbit/s for a 10 MB transfer (see `docs/bandwidth-benchmark.md`
for the full numbers).

If something is off, see [`troubleshooting.md`](troubleshooting.md).

## Uninstall

```sh
pkg delete os-trusttunnel
```

`+POST_DEINSTALL.post` cleans up:

- Any orphan firewall rules tagged `<plugin_managed>os-trusttunnel</plugin_managed>`
- The runtime directories `/usr/local/etc/trusttunnel` and `/var/log/trusttunnel`

Trust-store certs that you created via the plugin remain in System →
Trust (uninstalling the plugin shouldn't remove your certs).
