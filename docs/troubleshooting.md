# Troubleshooting — os-trusttunnel

Real issues observed during v1 development on OPNsense 26.1.8_5,
with their causes and fixes.

## Plugin UI

### **Menu entry `VPN → TrustTunnel` missing after install**

ACL/menu caches aren't rebuilt automatically when the plugin lands.

```sh
configctl webgui restart
```

If still missing, verify the ACL XML loaded:

```sh
sed -n '/<webgui>/,/<\/webgui>/p' /conf/config.xml | head -10
test -f /usr/local/opnsense/mvc/app/models/OPNsense/TrustTunnel/ACL/ACL.xml
```

### **All API endpoints return "Action not allowed or missing"**

The `configd` action namespace is space-separated, not dot-separated.
This was the v1 dispatcher bug fixed in commit `8c0f85f`.

Verify each `configdRun` call uses spaces:

```sh
grep -rn 'configdRun\|configdpRun' \
  /usr/local/opnsense/mvc/app/controllers/OPNsense/TrustTunnel/Api/
# Each line should look like 'trusttunnel server reconfigure', not
# 'trusttunnel server.reconfigure'.
```

### **Buttons don't trigger any AJAX**

Forms didn't render. Endpoint forms `server.xml`/`user.xml` and client forms
`client.xml`/`peer.xml` live in different plugin trees. Check both trees and
their controllers:

```sh
ls /usr/local/opnsense/mvc/app/controllers/OPNsense/TrustTunnel/forms/
ls /usr/local/opnsense/mvc/app/controllers/OPNsense/TrustTunnelClient/forms/
grep -A 1 'getForm' \
  /usr/local/opnsense/mvc/app/controllers/OPNsense/TrustTunnel/IndexController.php
grep -A 1 'getForm' \
  /usr/local/opnsense/mvc/app/controllers/OPNsense/TrustTunnelClient/IndexController.php
```

## Server (endpoint)

### **`trusttunnel_endpoint is not running` after `Apply`**

Look in OPNsense system log under the `trusttunnel-server` facility.
Common panic messages:

```
Couldn't parse the TLS hosts settings file: ... cert.pem error=No such file or directory
```

Cert wasn't materialised. Either `cert_ref` is wrong (no matching `<cert>`
in `/conf/config.xml`) or `materialize_certs.php` didn't run. Re-trigger
the action chain:

```sh
configctl trusttunnel server reconfigure
ls -la /usr/local/etc/trusttunnel/server/certs/
```

Версия 2.1.0 формирует `cert.pem` как leaf certificate плюс связанный OPNsense
`caref`. Если CA удалён, Apply завершается ошибкой вместо отправки неполной
цепочки.

```
Couldn't create core instance: SettingsValidation(Invalid listen protocols settings: Not set)
```

`vpn.toml` has the old `protocols = [...]` array form. Re-deploy the
plugin (commit `9d9ffe1` switched the renderer to per-protocol
sub-tables `[listen_protocols.http2]` / `[listen_protocols.quic]`).

```sh
grep -A 2 'listen_protocols' /usr/local/etc/trusttunnel/server/vpn.toml
```

### **Port 443 is held by `lighttpd` (Web UI), endpoint can't bind**

OPNsense Web UI использует этот порт по умолчанию. Варианты:

1. В **System → Settings → Administration** смените TCP port Web UI на 8443,
   сохраните и примените настройку штатным интерфейсом. До Apply подтвердите
   доступность management path и подготовьте rollback.
2. В TrustTunnel plugin UI привяжите Endpoint к отдельному адресу, например
   `Listen address = 203.0.113.10:443`.

Не редактируйте `/conf/config.xml` напрямую: automation должна использовать
штатный OPNsense API с backup, validation и config lock.

### **Server log shows the client connecting then "unexpected end of file"**

That's a normal post-response close from a remote target. Look for
`Successfully connected to TcpConnectionMeta { destination: Address(... }`
on the same `CONN=` ID — if you see it, the tunnel forwarded a CONNECT
request and the upstream replied + closed.

## Client (FreeBSD)

Upstream `v1.1.5-rc.6` не содержит FreeBSD TUN backend, но текущий port overlay
добавляет его как `net/src/os_tunnel_freebsd.cpp`. Установленный пакет должен
создавать рабочий `tun<N>`; одного `--version` для проверки недостаточно.

### **Клиент не создаёт `tun<N>` или пишет `Tunnel create error`**

Сначала проверьте backend без реального endpoint из checkout репозитория.
Команда требует root:

```sh
BOUND_IF=vtnet0  # замените на фактический исходящий интерфейс узла
sh tests/freebsd_client_tun_smoke.sh \
  /usr/local/sbin/trusttunnel_client "$BOUND_IF"
```

Ожидаются четыре сообщения `PASS`: create с `UP,POINTOPOINT` и MTU 1350,
cleanup owned TUN, отказ занять existing TUN без `use_existing=true` и
attach-mode с удалением managed route. При ошибке убедитесь, что пакет собран
из текущего overlay и в бинарник включён `os_tunnel_freebsd.cpp`.

### **Некорректное имя устройства или интерфейс остаётся после остановки**

`device_name` должен быть пустым для автоматического `/dev/tun` или иметь вид
`tun<N>`. Backend получает фактическое имя через `TUNGIFNAME` с полным
`struct ifreq`, устанавливает `TUNSIFHEAD=0` и удаляет только созданный им
интерфейс. В create-mode явно заданный `tun<N>` должен быть свободен, иначе
запуск отклоняется. При `use_existing=true` оператор отвечает за жизненный
цикл существующего TUN; backend сохраняет его адреса и MTU, переключает
packet-header mode и снимает при stop только добавленные managed routes.

Проверьте активный интерфейс и завершение процесса:

```sh
ifconfig -l | tr ' ' '\n' | grep '^tun[0-9][0-9]*$'
service trusttunnel_client onestatus
```

Status считается успешным только когда живы и `daemon(8)` supervisor, и его
непосредственный `trusttunnel_client` child. Во время пятисекундной задержки
перезапуска команда возвращает non-zero; при crash-loop результат может
чередоваться, поэтому единичный зелёный status не доказывает устойчивость или
работающий data plane. Живой supervisor сам по себе также недостаточен.

### **Маршрут есть, но трафик не проходит**

Текущий backend настраивает IPv4 POINTOPOINT, маршруты из `included_routes` и
MTU из конфигурации (default модели plugin 1350; снижайте только при провале
PMTUD). IPv6 пока недоступен.
Проверьте имя, адреса, MTU, маршрут и счётчики:

```sh
ifconfig tun0
route -n get 1.1.1.1
netstat -ibn | grep tun0
```

Для функциональной проверки нужны одновременно TLS-маркеры подключения,
TCP-ответ через туннель, UDP DNS, рост входящих и исходящих счётчиков без
ошибок и cleanup после остановки. Локальный E2E на OPNsense 26.7.3_8 / FreeBSD
ABI 1501000 это подтвердил; production-путь проверяется отдельно.

### **Custom SNI виден, но endpoint пишет `SNI authentication failed`**

Не задавайте `custom_sni` в форме `<label>.<main-host>`: endpoint разбирает
такое имя как SNI-аутентификацию `<credentials>.<main-host>` раньше списка
`allowed_sni`. Используйте отдельное разрешённое имя, например
`front.example.net`, добавьте его в `allowed_sni` соответствующего main host и
оставьте certificate identity в `hostname`. Проверяйте фактический ClientHello
capture на endpoint и затем TCP/UDP data plane, а не только состояние
`VPN_SS_CONNECTED`.

### **Клиент пытается менять системный DNS**

FreeBSD backend намеренно не заменяет системный DNS. Оставьте
`change_system_dns = false` и управляйте DNS через OPNsense. Плагин отклоняет
конфигурацию с включённой заменой DNS; не обходите эту проверку прямым запуском
клиента.

### **General log заполнен traceback от `onestatus`**

`rc.d onestatus` возвращает exit `1`, если daemon штатно остановлен. В
configd action `[server.status]` или `[client.status]` должны одновременно
присутствовать `type: script_output` и `errors:no`; это стандартный контракт
OPNsense для status actions. После обновления plugin package проверьте action и
перезапустите только configd, если package hook ещё не сделал этого:

```sh
grep -A5 '^\[server.status\]' \
  /usr/local/opnsense/service/conf/actions.d/actions_trusttunnel.conf
grep -A5 '^\[client.status\]' \
  /usr/local/opnsense/service/conf/actions.d/actions_trusttunnelclient.conf
service configd restart
```

`errors:no` относится только к диагностическому status: ошибки start,
reconfigure и самого TrustTunnel по-прежнему должны останавливать action.

### **Восемь `UPSTREAM_MUX` ошибок появляются одновременно**

Сначала подтвердите transport по rendered config и сокетам:

```sh
grep upstream_protocol \
  /usr/local/etc/trusttunnel/client/trusttunnel_client.toml
sockstat -46c | grep trusttunnel
```

При HTTP/2 Client держит пул из восьми TCP upstream sockets. Рестарт Endpoint
закрывает весь пул одновременно и даёт группу `TCP socket error` / `Resource
temporarily unavailable`. UDP listener Endpoint сам по себе не доказывает
HTTP/3.

Сборка без recovery patch может застрять, если закрытые сессии успели получить
replacement в состоянии `OPENING`: пул не пуст, но использовать нечего, а
health-check не инициирует повторный выбор endpoint. В текущем overlay это
состояние поднимает `SERVER_EVENT_HEALTH_CHECK_ERROR`; нормальная
диагностика содержит `Health check error: There are no open upstream sessions
(1)`, а ожидаемая успешная последовательность — `VPN_SS_WAITING_RECOVERY`,
`VPN_SS_RECOVERING`, `VPN_SS_CONNECTED` без смены supervisor/child PID.
Отсутствие `CONNECTED` и возврата прикладного трафика считается отказом, даже
если процесс жив.

Если группы ошибок идут без изменения Endpoint или число FD растёт, снимайте
динамику `procstat -f <client-pid>` и считайте это отдельным FD/reconnect
инцидентом. Проверяйте именно реальный TCP/UDP поток через `tun<N>`: одна строка
status не доказывает восстановление data plane.

Client rc.d supervisor перезапустит аварийно завершившийся child через 5 секунд,
но это только восстановление сервиса, а не исправление возможной upstream-утечки.

### **Sustained transfer даёт `tun<N> Drop`, хотя TCP не повреждён**

Снимайте до/после не только application hash, но и интерфейсные счётчики:

```sh
netstat -I tun0 -bdn
netstat -m
procstat -f "$(pgrep -o trusttunnel_client)" | wc -l
```

Колонка `Drop` у `tun<N>` — потери output queue; нулевые `Ierrs/Oerrs` и
совпавший SHA256 их не отменяют. `netstat -m` отделяет переполнение очереди TUN
от нехватки mbuf. Не маскируйте проблему увеличением FD limit: сначала уменьшите
burst/rate теста и убедитесь, что число FD возвращается к baseline. FreeBSD
создаёт TUN с текущим `net.link.ifqmaxlen`; этот параметр является boot-time
read-only tunable. Его изменение требует отдельного maintenance preflight,
штатного reboot и пересоздания TUN, поэтому не применяйте его во время live
transfer.

Full-duplex harness не должен делать `shutdown(SHUT_WR)`, пока встречное
направление ещё передаёт данные: текущий data plane закрывает весь flow после
half-close. Дождитесь точного количества байт в обе стороны, проверьте оба
SHA256 и только затем закрывайте socket.

### Архив: портирование v1.1.4

Следующие симптомы относятся только к историческому портированию v1.1.4 и не
описывают текущий backend `v1.1.5-rc.6`.

#### **`trusttunnel_client` opens `tun0` and then exits**

Symptom from the v1 dev cycle: process gone, but `tun0` interface
remains in the system. Caused by:

1. **`TUNGIFNAME` buffer overflow** (fixed round 25). The ioctl writes a
   full `struct ifreq` (~40 B), not just `char[IFNAMSIZ]`. Stack
   overflow corrupted the `this` register. See round 25 commit.
2. **`SocketAddressStorage` byte layout** (fixed round 23). FreeBSD
   `sockaddr_in` is `uint8_t sin_len + uint8_t sin_family`, like Apple
   — extend the `__APPLE__` guard.
3. **`is_running()` undefined return** (fixed round 21).
   `network_monitor.cpp` had no FreeBSD branch — undefined behaviour
   crashed `~NetworkMonitorImpl`. Plugin's recipe patches add a
   FreeBSD branch.

All three are in `docs/freebsd-port-patches.md`. If you built from
scratch and didn't apply them, the binary will crash at one of these
points.

#### **`Outbound interface is not specified` config error**

`parse_tun_listener_config` rejects FreeBSD on unpatched upstream
sources. The plugin's `config.cpp` patch extends the `#if defined`
guard to include `__FreeBSD__`. If using a self-built binary from
unmodified upstream, the patches in `docs/freebsd-port-patches.md`
group D1 are required.

#### **`bound_if` set, NetworkMonitor still hangs**

`auto_network_monitor.cpp` patch (commit `93873ca`) adds an early
return on FreeBSD when `bound_if` is non-empty — skips the
NetworkMonitor that has no FreeBSD impl. Verify your client config
has `bound_if = "vtnet0"` (or your actual outbound interface) under
`[listener.tun]`.

#### **`Address family not supported (47)`** when starting DNS proxy

Linux `AF_KCM = 47`; FreeBSD has no such family. Caused by
`SocketAddressStorage` reading the wrong byte as `sa_family` (the
core bug fixed in round 23). With patches applied, you should see
`AF_INET = 2` in the socket call.

#### **Packets leave `tt0` but no response**

The TUN device on FreeBSD is POINTOPOINT — needs a peer IP. The
plugin's `setup_if` writes:

```
ifconfig tt0 inet 192.0.2.2 192.0.2.1 netmask 255.255.255.0 mtu 1280 up
```

Verify:

```sh
ifconfig tt0
# inet 192.0.2.2 --> 192.0.2.1 netmask 0xffffff00
```

For test routing, add a host route via the TUN device:

```sh
route add -host 1.1.1.1 -interface tt0
curl http://1.1.1.1/
```

If you see `Opkts` increment on `tt0` but no `Ipkts`, the server
hasn't received the encapsulated CONNECT request. Check server log
under `trusttunnel-server` facility for `New TCP client:` entry.

## Network / firewall

### **WAN-правило OPNsense создано через API, но трафик блокируется**

Plugin не создаёт firewall rules. Найдите exact rule через
`GET /api/firewall/filter/searchRule`, затем проверьте:

1. Rule order — must be above any blanket Block rule.
2. Listen address matches the rule's `destination` field.
3. State info — `pftop` shows established sessions.

### **`os error 54` Connection reset**

Remote upstream dropped the connection. The TrustTunnel server logs
this as `Error on pipe: Connection reset by peer (os error 54)`.
It's not a tunnel bug — the destination service rejected the
forwarded request for its own reasons (rate-limit, ACL, etc.).

## HA failover

### **xmlrpc sync ships an empty `<trusttunnel/>`**

Check that `plugins.inc.d/trusttunnel.inc` is installed on **both**
nodes and registered:

```sh
test -f /etc/inc/plugins.inc.d/trusttunnel.inc && \
  grep 'trusttunnel_xmlrpc_sync' /etc/inc/plugins.inc.d/trusttunnel.inc
```

The hook returns:

```php
[
  'id' => 'trusttunnel',
  'section' => 'OPNsense.trusttunnel',
  'description' => 'TrustTunnel VPN',
  'services' => ['trusttunnel_endpoint','trusttunnel_client'],
]
```

Section path is lowercase by OPNsense convention. Passwords ride on
`nosync="1"` field-level stripping — they're left blank on the
secondary; operator must re-enter them after promoting the secondary.

### **WAN-правило продублировалось на secondary**

Plugin не выполняет HA sync firewall rules. Сравните exact UUID через
`GET /api/firewall/filter/searchRule` на каждой ноде и удалите только
подтверждённый duplicate штатным `delRule/<uuid>`. Не правьте `config.xml`.

## Performance

### **Throughput lower than `docs/bandwidth-benchmark.md` numbers**

The benchmark numbers (197 Mbit/s single, 114 Mbit/s sustained) were
measured on:

- Gigabit Proxmox bridge (`vmbr0`), no real WAN
- Two same-host VMs (no inter-host latency)
- Cachefly CDN — known fat-pipe target

Real-world WAN deployments see lower numbers due to:

- ISP egress shaping
- Encryption overhead on slower CPUs (no AES-NI fallback)
- TCP windowing on long-RTT paths

Use `iperf3 -c <iperf-server>` via the tunnel for an apples-to-apples
measurement against your link's raw capacity.

### **`tun0 Opkts` count rising but no response**

Two common causes:

1. **Server-side firewall blocks outbound** — the TrustTunnel
   endpoint daemon needs to make outbound connections to whatever the
   client is asking for. OPNsense's default outbound NAT/Pass on WAN
   allows this; if you tightened it, the daemon's outbound TCP gets
   blocked.
2. **No route back on the client side** — the kernel routes the
   response packet but it doesn't return to the user app. Verify by
   running `tcpdump -i tun0 -nn icmp` while pinging.

## Diagnostics

A quick "is the tunnel working" health check:

```sh
# Server side
sockstat -l4 | grep ':443'
service trusttunnel_endpoint onestatus
grep trusttunnel-server /var/log/system/system_*.log | tail -20

# Client side
service trusttunnel_client onestatus
ifconfig tun0
netstat -ibn | grep tun0
grep trusttunnel-client /var/log/system/system_*.log | tail -20
```

If a step in this list fails, the corresponding section above has the
fix.
