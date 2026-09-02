# Changelog — os-trusttunnel

All notable changes per release. Newest first.

> Записи ниже описывают результаты соответствующей версии. Они не являются
> доказательством совместимости текущих FreeBSD 15.1 packages.

## v2.1.0 (2026-09-02, не опубликован)

### Добавлено

- FreeBSD `tun(4)` backend для TrustTunnelClient `v1.1.5-rc.6`: создание и
  удаление принадлежащего клиенту интерфейса, point-to-point адресация,
  маршруты, MTU и cleanup при остановке.
- Контрактные Python-тесты для client renderer и OPNsense plugin, а также
  `tests/freebsd_client_tun_smoke.sh` для проверки lifecycle TUN на FreeBSD.

### Исправлено

- Client renderer теперь формирует контракт upstream `v1.1.5-rc.6`, использует
  namespace `trusttunnelclient`, сохраняет отдельные `hostname`/`custom_sni`
  и атомарно записывает конфигурацию. Изменение системного DNS на OPNsense
  запрещено; DNS управляется самой OPNsense.
- Формы, MVC response path, `configd` actions и rc.d pidfile согласованы с
  раздельным client plugin. Версии обоих плагинов повышены до `2.1.0`.
- Endpoint plugin явно зависит от `libqrencode`.
- Endpoint plugin принимает список `allowed_sni`, проверяет имена и отклоняет
  alias вида `<label>.<main-host>`, зарезервированный под SNI-аутентификацию.
- Endpoint materializer добавляет к выбранному leaf certificate связанный
  OPNsense `caref`, чтобы daemon отдавал полный TLS chain; dangling CA
  останавливает Apply с ошибкой.
- Endpoint renderer валидирует все TOML до записи, не создаёт смешанный набор
  при ошибке и не задаёт `credentials_file`, пока пользователи отсутствуют.
- Endpoint Apply, как и client Apply, успешен только при отдельной строке `OK`
  от завершившейся configd-chain.
- Client plugin требует физический `bound_if` на FreeBSD и считает запуск
  успешным только при явном ответе `OK` от `configd`.
- Endpoint больше не пишет legacy firewall и System Trust напрямую через
  `Config::save()`. Сертификаты и WAN rules принадлежат штатным API
  `/api/trust/cert/*` и `/api/firewall/filter/*`; package install/uninstall
  изменяет только plugin model и производные runtime-файлы.

### Проверено

- Локальный E2E на OPNsense `26.7.3_8`, FreeBSD ABI `1501000`: TLS certificate
  verification и negative identity check, `VPN_SS_CONNECTED`, маршрут через
  `tun0` с MTU 1350, HTTP 301, UDP DNS, рост счётчиков `0/0 -> 929/690` без
  interface errors и удаление TUN/маршрута после остановки клиента.
- Endpoint-side capture подтвердил certificate identity `api.www1.ru` и
  отличный `custom_sni=www1.ru`; Let’s Encrypt certificate с SAN
  `*.www1.ru`/`www1.ru`, TCP и UDP DNS прошли через одну сессию. Alias не
  являлся поддоменом main hostname, поскольку этот формат зарезервирован
  upstream под SNI-аутентификацию.
- Этот результат подтверждает только тестовый стенд. Production deployment,
  HA, длительная нагрузка и production routing ещё не подтверждены; GitHub
  Release `v2.1.0` на момент этой записи не создан.
- Package lifecycle smoke на изолированной OPNsense VM подтвердил, что
  `pkg delete` удаляет derived runtime Endpoint, а uninstall и повторная
  установка сохраняют полный SHA256 `/conf/config.xml` без изменений.

## v2.0.0 (2026-05-25)

### Changed (breaking — plugin split)
- **Split the single `os-trusttunnel` plugin into two role-specific
  plugins** so a host only pulls the binary it needs:
  - `os-trusttunnel` — server role. `PLUGIN_DEPENDS=trusttunnel qrencode`.
    Drops the `trusttunnel-client` dependency entirely. The Rust endpoint
    binary builds cleanly (PR #28), so an endpoint-only box is no longer
    coupled to the fragile ~30-patch C++/Rust client build.
  - `os-trusttunnel-client` — client role. `PLUGIN_DEPENDS=trusttunnel-client`.
    No server binary, no qrencode.
- **Repo restructured** to `net/os-trusttunnel/` + `net/os-trusttunnel-client/`,
  each a standard OPNsense plugin dir (Makefile + src/). `freebsd-port/`
  and `docs/` stay shared at repo root.
- **Config namespaces separated**: server writes
  `<OPNsense><trusttunnel>`, client writes `<OPNsense><trusttunnelclient>`
  (mount `//OPNsense/trusttunnelclient`). No collision on HA-sync or
  uninstall — each plugin owns its subtree.
- **Menus**: VPN → TrustTunnel (server) and VPN → TrustTunnel Client
  (client) are now separate entries.
- **UI**: each plugin renders a single-purpose view (no more server/client
  tabs). DeeplinkController split — export lives in the server plugin,
  import/preview/confirm in the client plugin.
- **configd actions** split into `actions_trusttunnel.conf` (server.*) and
  `actions_trusttunnelclient.conf` (client.*); the client topic is
  `trusttunnelclient`.
- **Hooks** split per plugin: server keeps services/syslog(trusttunnel-server)/
  xmlrpc; client keeps services/syslog(trusttunnel-client)/devices(tt<N>)/
  xmlrpc. Function names prefixed `trusttunnelclient_*` in the client.
- **Deinstall isolation**: each `+POST_DEINSTALL` removes only its own
  config subdir and `rmdir`s the shared parent only when empty — uninstalling
  one plugin never destroys the other's state.

### Verified
- Both `.pkg` build cleanly via `pkg-static create`; file lists confirm
  zero cross-contamination (server pkg has no client files and vice versa).

### Migration
- `os-trusttunnel` keeps its name (non-breaking for existing installs of
  the server role). To get the client role, install the new
  `os-trusttunnel-client`. A box that had the old combined plugin and used
  the client tab should install `os-trusttunnel-client` and re-import its
  deeplink (client config now lives under `<trusttunnelclient>`).

## v1.0.2 (2026-05-23)

### Fixed
- **`services()` hook** in `src/etc/inc/plugins.inc.d/trusttunnel.inc`
  used dotted action names (`trusttunnel server.restart`) that the
  configd action loader does not accept (sections like `[server.restart]`
  become nested map keys; invocation must use space-separated tokens).
  Fixed all 6 invocations to `trusttunnel server {restart,start,stop}` /
  `trusttunnel client {restart,start,stop}`. Same root cause as commit
  `8c0f85f` (controllers) but the hook wasn't exercised by Apply, only
  by Services → Services manual Start/Stop/Restart UI.

### Documentation
- **`docs/architecture.md`** — ASCII component map for the whole tree,
  Server/Client Apply data flow diagrams, deeplink TLV protocol details,
  OPNsense hook table (services/devices/syslog/xmlrpc_sync), firewall
  rule shape with `<plugin_managed>` marker, TUN device lifecycle on
  FreeBSD.
- **`docs/troubleshooting.md`** — all real issues from the v1 dev
  cycle with their root causes: configd dotted vs space, TUN buffer
  overflow, SocketAddressStorage byte layout, port :443 conflict with
  lighttpd, NetworkMonitor is_running() UB, AF_47 → AF_INET, perf
  expectations, HA failover.
- **`docs/install.md`** — rewritten to cover two paths: GitHub Releases
  direct (testing) and signed pkg repo (production fleets). Includes
  step-by-step first-run for both Server and Client tabs, site-to-site
  setup mirroring WireGuard s2s guide, and a `curl` bandwidth verify
  recipe.

## v1.0.1 (2026-05-23)

### END-TO-END DATA PLANE VERIFIED 🎉

Full HTTP traffic forwarded through FreeBSD VPN tunnel verified
2026-05-23 08:05:30:

```
$ curl --max-time 8 -o /tmp/cf.txt http://1.1.1.1/  # routed via tun0
HTTP 301 0.093116s
```

Server side (--loglvl debug):
```
[CLIENT=0] New TCP client: <client-address>:46656
[CLIENT=0] TLS13_CHACHA20_POLY1305_SHA256 ALPN h2
[CLIENT=0/TUN=0/CONN=3] Received: CONNECT 1.1.1.1:80
[CLIENT=0/TUN=0/CONN=3] Successfully connected to destination 1.1.1.1:80
```

Client tun0 bidirectional counters: Ipkts=4 Ibytes=606 / Opkts=6 Obytes=391.

### Fixed
- **`render_server_config.py`** — emit `[listen_protocols.http2]` /
  `[listen_protocols.quic]` per-protocol sub-tables instead of the
  invalid `protocols = [...]` array form. Map `http3 → quic` to match
  the endpoint `ListenProtocolSettings` struct field name. Endpoint
  no longer fails startup with "Invalid listen protocols settings:
  Not set".
- **`os_tunnel_freebsd.cpp` `setup_if()`** — use POINTOPOINT-aware
  `ifconfig tunN inet LOCAL PEER netmask MASK mtu MTU up` instead of
  CIDR `inet ADDR/PREFIX up` (invalid on tun interfaces). Synthesize
  peer = LOCAL with `.1` last octet.
- **`os_tunnel_freebsd.cpp` `setup_dns()`** — atomic tempfile +
  `std::rename()` write of `/etc/resolv.conf`, replacing
  `printf '%s' '…' > /etc/resolv.conf` shell pipeline that triggered
  `sh: nameserver: not found` warnings on multi-line content.

### Documented (no-code)
- **`docs/freebsd-port-patches.md`** (484 lines) — canonical
  reproducible reference for the ~30 patches required to build
  TrustTunnel client on FreeBSD: 11 in NativeLibsCommon, 1 in
  DnsLibs, 17 in TrustTunnelClient source + new `os_tunnel_freebsd.cpp`.
  Grouped by upstream destination to enable independent PRs.
- **Auto-spawned SOCKS5 listener at 127.0.0.1:9972** — upstream
  TrustTunnel behavior (from `core/src/socks_listener.cpp`), not
  configurable via plugin `[listener.tun]` config. Auth handshake
  governed by upstream defaults.

### Known limitations (v1.0.1 backlog → v1.0.2)
- Server-side L3 forwarding requires manual OPNsense NAT rule on WAN
  for return traffic to reach the TUN device on the client side.
- TrustTunnel data plane is **per-connection proxy** (TCP CONNECT /
  UDP datagram forwarding), not a full L3 packet relay — pinging the
  endpoint's WAN IP from inside the tunnel does not echo back.

### Upstream PR pipeline (deferred to v2)
1. `AdGuardTeam/NativeLibsCommon` — Groups B1–B11 patches (~80 lines)
2. `AdGuardTeam/DnsLibs` — Group C1 patch (~5 lines)
3. `TrustTunnel/TrustTunnelClient` — Groups D1–D17 + new
   `os_tunnel_freebsd.cpp` (~250 lines)

## v1.0.0 (2026-05-23)

### Added
- **OPNsense plugin** (`mvc/app/`) — Server + Client tabs, bootgrid for
  users/servers, per-user **Export deeplink** (`tt://?…` URI + QR PNG),
  paste-and-confirm **Import deeplink** trust gate.
- **`security/trusttunnel` FreeBSD port** — wraps Cargo workspace,
  produces `trusttunnel_endpoint` + `trusttunnel_setup_wizard` binaries.
  Uses PR #28 (upstream FreeBSD support, merged Feb 2026).
- **`security/trusttunnel-client` FreeBSD port** — wraps CMake + Conan
  + Rust hybrid build with cumulative ~30 patches (B1–D17). Produces
  `trusttunnel_client` ELF 64-bit FreeBSD x86_64 binary.
- **`configd` action chain** — `[server.{start,stop,restart,status,
  reconfigure,export_deeplink}]` and `[client.{start,stop,restart,
  status,reconfigure}]` actions invokable via `configctl trusttunnel …`.
- **HA `xmlrpc_sync`** — config syncs to standby on CARP failover via
  `trusttunnel_xmlrpc_sync()`. Passwords stripped via `nosync="1"` on
  the model field.
- **Auto-firewall rule** — plugin creates and tracks a `pass inbound`
  rule on WAN matching the configured listen port. Visible in
  Firewall → Rules, marked with
  `<plugin_managed>os-trusttunnel</plugin_managed>`.
- **Per-user revocation** — drop a user from the Server bootgrid;
  endpoint restarts and existing connections fail on next handshake.

### Verified end-to-end
- Server: `trusttunnel_endpoint` listens on `*:443` TCP + UDP on
  an isolated test VM, alice user authenticated, deeplink
  exported successfully.
- Client (FreeBSD): `trusttunnel_client` builds + runs on a test VM,
  reaches `VPN_SS_CONNECTED`, opens `tun0`
  POINTOPOINT interface with `inet 192.0.2.2 --> 192.0.2.1`,
  and keeps the TCP control session alive.

### Fixed during initial iteration
- configd action invocation: `space-separated` not `dotted` event
  strings (`trusttunnel server reconfigure`, not
  `trusttunnel server.reconfigure`).
- OPNsense model XML: `UniqueIdField` (not `UUIDField`),
  `IPPortField` (not `NetworkAddressField`), `Mask` inline (not
  `RegexConstraint`).
- ArrayField rows: `type="ArrayField"` MUST be on inner row template.
- Trust-gate bypass: `confirmImportAction` re-parses URI server-side.
- DoS mitigation: `proc_get_status` polling + `proc_terminate(9)` on
  10s deadline for `deeplink_parse.py` subprocess.
- `setAction` override: tun_interface clash check via `ifconfig -l`.
- Trust store: `<cert_ref>` (not `<certificate>`) is the actual
  model field name for OPNsense CertificateField.

### Security
- `<password nosync="1">` strips secrets from HA wire payload.
- 64 KB URI cap on deeplink import (defense against DoS via huge cert).
- `credentials.toml` chmod 0600; atomic writes via tempfile +
  `os.replace`.
- `openssl_csr_new` pipeline with fail-loud `openssl_error_string()`
  drainage on each step.

### Compatibility
- OPNsense CE 25.x baseline + verified on 26.1.8_5.
- BSD-2-Clause license.
