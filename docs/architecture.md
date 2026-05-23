# Architecture — os-trusttunnel

One-page reference for the layout, data flow, and integration points.
For operational install/uninstall see `install.md`; for upstream patch
inventory see `freebsd-port-patches.md`.

## Component map

```
┌──────────────────────────────────────────────────────────────────┐
│ OPNsense (FreeBSD 14.x)                                          │
│                                                                  │
│ /usr/local/opnsense/mvc/app/                                     │
│ ├─ controllers/OPNsense/TrustTunnel/                             │
│ │   ├─ IndexController.php       — loads volt views + forms      │
│ │   ├─ forms/{server,user,                                       │
│ │   │         client,peer}.xml   — base_form/base_dialog fields  │
│ │   └─ Api/                                                      │
│ │      ├─ ServerController.php   — users CRUD, gen cert,         │
│ │      │                            reconfigure, syncFirewallRule│
│ │      ├─ ClientController.php   — servers CRUD, setActive       │
│ │      ├─ DeeplinkController.php — import (trust gate), export   │
│ │      └─ ServiceController.php  — start/stop/restart/status     │
│ ├─ models/OPNsense/TrustTunnel/                                  │
│ │   ├─ TrustTunnel.xml           — model schema (server/client)  │
│ │   ├─ ACL/ACL.xml               — VPN: TrustTunnel privilege    │
│ │   └─ Menu/Menu.xml             — VPN → TrustTunnel menu        │
│ └─ views/OPNsense/TrustTunnel/                                   │
│     ├─ index.volt                — hash-routed tabs container    │
│     ├─ server.volt               — Server tab UI + dialogs       │
│     └─ client.volt               — Client tab UI + trust gate    │
│                                                                  │
│ /usr/local/opnsense/scripts/trusttunnel/                         │
│ ├─ render_server_config.py       — TOML render from config.xml   │
│ ├─ render_client_config.py       — TOML render from config.xml   │
│ ├─ materialize_certs.php         — cert/key PEMs from Trust store│
│ ├─ deeplink_export.py            — tt://? URI + QR PNG           │
│ └─ deeplink_parse.py             — tt://? URI → JSON (stdin)     │
│                                                                  │
│ /usr/local/opnsense/service/conf/actions.d/                      │
│ └─ actions_trusttunnel.conf      — configd actions (11 verbs)    │
│                                                                  │
│ /usr/local/etc/rc.d/                                             │
│ ├─ trusttunnel_endpoint          — daemon(8) wrap                │
│ └─ trusttunnel_client            — daemon(8) wrap                │
│                                                                  │
│ /etc/inc/plugins.inc.d/trusttunnel.inc                           │
│ └─ services(), devices(), syslog(), xmlrpc_sync() hooks          │
│                                                                  │
│ /usr/local/sbin/                                                 │
│ └─ trusttunnel_endpoint          — Rust binary (Server role)     │
│                                                                  │
│ /usr/local/bin/                                                  │
│ ├─ trusttunnel_client            — C++/Rust binary (Client role) │
│ └─ trusttunnel_setup_wizard      — Rust binary (config helper)   │
└──────────────────────────────────────────────────────────────────┘
```

## Runtime artifacts

State lives in two places:

1. **`/conf/config.xml`** — authoritative `<OPNsense><trusttunnel>`
   subtree. UI Save / `set` API writes here, `xmlrpc` HA-sync ships
   it.
2. **`/usr/local/etc/trusttunnel/{server,client}/`** — derived TOML
   files regenerated from `config.xml` on every `reconfigure`. Never
   read back. `credentials.toml` is `chmod 0600`; the rest are `0644`.

```
/usr/local/etc/trusttunnel/server/
├─ vpn.toml          — listen_address, listen_protocols.{http2,quic}
├─ hosts.toml        — main_hosts[].cert_chain_path + private_key_path
├─ credentials.toml  — alice/$pw bcrypt-equivalent (TrustTunnel format)
├─ rules.toml        — client_random_prefix filtering rules
└─ certs/
   ├─ cert.pem       — materialized from Trust store via PHP openssl
   └─ key.pem        — 0600
/usr/local/etc/trusttunnel/client/
└─ trusttunnel_client.toml — endpoint=..., listener.tun.bound_if=..., mode=...
```

## Server data flow (Apply path)

```
UI Save → POST /api/trusttunnel/server/set
       ↓
ServerController::setAction()    — validates via model XML, writes
       ↓                            <OPNsense><trusttunnel><server>
syncFirewallRule()                — upserts <filter><rule> with
       ↓                            <plugin_managed>os-trusttunnel</…>
                                    UUID stored in <server><firewall_rule_uuid>
POST /api/trusttunnel/server/reconfigure
       ↓
configd: trusttunnel server reconfigure
       ↓
materialize_certs.php             — read <cert refid=…>/crt + prv,
       ↓                            base64-decode → cert.pem + key.pem
render_server_config.py           — atomic tempfile + os.replace
       ↓                            for each of 4 TOML files
/usr/local/etc/rc.d/trusttunnel_endpoint onerestart
       ↓
daemon -S -T trusttunnel-server …
       ↓
trusttunnel_endpoint vpn.toml hosts.toml
       ↓ (listens on :443 TCP + UDP, accepts h2/quic via TLS)
```

Logs land on the `trusttunnel-server` syslog facility →
`/var/log/system/system_YYYYMMDD.log` (collected via OPNsense's syslog-ng
config generated by the `syslog()` hook).

## Client data flow (Apply path)

```
UI Save → POST /api/trusttunnel/client/set
       ↓ (also setActive)
config.xml <client><servers> + <active_server>
       ↓
POST /api/trusttunnel/client/reconfigure
       ↓
configd: trusttunnel client reconfigure
       ↓
render_client_config.py           — pick the active server row,
       ↓                            inline its <certificate_pem>,
                                    write trusttunnel_client.toml
/usr/local/etc/rc.d/trusttunnel_client onerestart
       ↓
daemon -S -T trusttunnel-client …
       ↓
trusttunnel_client -c …/trusttunnel_client.toml
       ↓ open /dev/tun (auto-clone) → tunN
       ↓ ifconfig tunN inet <local> <peer> netmask … mtu 1280 up
       ↓ route add for included_routes via tunN
       ↓ TLS handshake to <hostname>:<port>, ALPN h2
       ↓ HTTP/2 CONNECT _check (health) + CONNECT _udp2 (UDP demux)
       ↓ tcpip stack on top of tunN read/write
```

## Deeplink protocol

`tt://?<base64url-blob>` is a custom TLV format defined in
`PROTOCOL.md` (TrustTunnel repo). 14 tags (0x00..0x0D) cover hostname,
addresses[], username, password, certificate (DER), upstream_protocol,
client_random_prefix[/mask], skip_verification, ipv6_available,
custom_sni, name, dns_upstreams.

Encoded with RFC 9000 §16 varints + Bencode-style typed values.

### Export (server side)

```
configd: trusttunnel server export_deeplink <username>
  → deeplink_export.py --user=<username>
    → read <config.xml>, locate user row, gather hostname/port/cert
    → assemble TLV blob via scripts/config_to_deeplink.py (upstream
      Python helper, vendored)
    → qrencode --type=PNG → base64
    → JSON {"uri":"tt://?…","qr_png_base64":"iVBOR…"}
```

### Import (client side, trust gate)

```
POST /api/trusttunnel/deeplink/parse  {uri: "tt://?…"}
  → DeeplinkController::parseAction()
    → runDeeplinkParse(uri):
      - 64 KB URI size cap
      - tt:// prefix check
      - spawn python3 deeplink_parse.py via proc_open
      - stream_set_blocking + proc_get_status polling with 10 s deadline
      - on timeout: proc_terminate(9); on failure: stderr captured
    → returns parsed {hostname, fingerprint_sha256, username, …}
UI shows the trust-gate preview modal.

User clicks "Confirm Import":
POST /api/trusttunnel/deeplink/confirmImport  {uri: "tt://?…"}
  → re-parse server-side (NOT trusting the client-supplied JSON —
    closes the trust-gate bypass)
  → write into <client><servers><server uuid=…> array
  → if first server, set as active_server
```

## OPNsense integration hooks

`/etc/inc/plugins.inc.d/trusttunnel.inc`:

| Hook                            | Returns                                                                |
| ------------------------------- | ---------------------------------------------------------------------- |
| `trusttunnel_services()`        | services list for **Services → Services** start/stop UI                |
| `trusttunnel_devices()`         | `[{pattern: '^tt[0-9]+'}]` → device visible in Interfaces → Assignments|
| `trusttunnel_syslog()`          | syslog facilities `trusttunnel-server` and `trusttunnel-client`        |
| `trusttunnel_xmlrpc_sync()`     | `[id, section, description, services]` for CARP HA sync                |

The section path `OPNsense.trusttunnel` is lowercase by OPNsense
convention (XMLRPC keys). PHP namespace + menu tag remain PascalCase
per PSR-4.

## Firewall integration

`syncFirewallRule()` in `ServerController.php` upserts a single rule
into `/conf/config.xml` under `<filter>`:

```xml
<rule uuid="…">
  <plugin_managed>os-trusttunnel</plugin_managed>
  <descr>Auto: TrustTunnel inbound (managed by os-trusttunnel; do not edit)</descr>
  <type>pass</type>
  <interface>wan</interface>
  <ipprotocol>inet</ipprotocol>
  <protocol>tcp</protocol>
  <source><any>1</any></source>
  <destination>
    <address>203.0.113.10</address>  <!-- or <any>1</any> for 0.0.0.0:port -->
    <port>443</port>
  </destination>
</rule>
```

UUID is stored in `<server><firewall_rule_uuid>` so subsequent
reconfigures upsert (not duplicate). On Server disable, the rule is
removed.

## TUN device lifecycle (FreeBSD)

```
trusttunnel_client startup
  → VpnFreeBsdTunnel::tun_open()
    → open("/dev/tun", O_RDWR)  — auto-clones a free tunN
    → ioctl(fd, TUNGIFNAME, &ifreq)  — get device name
    → ioctl(fd, TUNSIFHEAD, &0)      — Linux-compatible raw IP (no AF prefix)
    → if_nametoindex(m_tun_name)
  → VpnFreeBsdTunnel::setup_if()
    → ifconfig tunN inet <local> <peer> netmask … mtu … up
    → ifconfig tunN inet6 <local6> prefixlen …
  → VpnFreeBsdTunnel::setup_routes()
    → route -q add -net X.X.X.X/N -interface tunN  (for each included_route)
    → route -q add -inet6 …                        (IPv6)
  → VpnFreeBsdTunnel::setup_dns()
    → tempfile = "nameserver A\nnameserver B\n…"
    → std::rename(tempfile, /etc/resolv.conf)  — atomic

shutdown:
  → close(fd)              — kernel releases tunN device
  → ifconfig tunN destroy  — explicit cleanup
```

## Cross-references

- **Wire-format & protocol details**: `PROTOCOL.md` in
  TrustTunnel upstream repo
- **Configuration field reference**: `CONFIGURATION.md` (upstream)
- **All FreeBSD patches with rationale**: `docs/freebsd-port-patches.md`
- **Performance numbers**: `docs/bandwidth-benchmark.md`
- **Release/distribution**: `docs/release.md`
- **Install/uninstall**: `docs/install.md`
- **Common issues**: `docs/troubleshooting.md`
