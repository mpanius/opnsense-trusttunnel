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

Forms didn't render. Check that `forms/{server,user,client,peer}.xml` are
present and the `IndexController.php` loads them:

```sh
ls /usr/local/opnsense/mvc/app/controllers/OPNsense/TrustTunnel/forms/
grep -A 1 'getForm' \
  /usr/local/opnsense/mvc/app/controllers/OPNsense/TrustTunnel/IndexController.php
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

OPNsense Web UI default port. Two fixes:

```sh
# Option 1 — move Web UI to 8443
python3 - <<'PY'
import re
fp='/conf/config.xml'
t=open(fp).read()
t=re.sub(r'(<webgui>.*?)<port/>', r'\1<port>8443</port>', t, flags=re.DOTALL)
t=re.sub(r'(<webgui>.*?<port>)[^<]*(</port>)', r'\g<1>8443\g<2>', t, flags=re.DOTALL)
open(fp,'w').write(t)
PY
configctl webgui restart
```

Option 2: bind the endpoint to a specific WAN IP via the plugin UI
(`Listen address = 203.0.113.10:443`).

### **Server log shows the client connecting then "unexpected end of file"**

That's a normal post-response close from a remote target. Look for
`Successfully connected to TcpConnectionMeta { destination: Address(... }`
on the same `CONN=` ID — if you see it, the tunnel forwarded a CONNECT
request and the upstream replied + closed.

## Client (FreeBSD)

### **`trusttunnel_client` opens `tun0` and then exits**

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

### **`Outbound interface is not specified` config error**

`parse_tun_listener_config` rejects FreeBSD on unpatched upstream
sources. The plugin's `config.cpp` patch extends the `#if defined`
guard to include `__FreeBSD__`. If using a self-built binary from
unmodified upstream, the patches in `docs/freebsd-port-patches.md`
group D1 are required.

### **`bound_if` set, NetworkMonitor still hangs**

`auto_network_monitor.cpp` patch (commit `93873ca`) adds an early
return on FreeBSD when `bound_if` is non-empty — skips the
NetworkMonitor that has no FreeBSD impl. Verify your client config
has `bound_if = "vtnet0"` (or your actual outbound interface) under
`[listener.tun]`.

### **`Address family not supported (47)`** when starting DNS proxy

Linux `AF_KCM = 47`; FreeBSD has no such family. Caused by
`SocketAddressStorage` reading the wrong byte as `sa_family` (the
core bug fixed in round 23). With patches applied, you should see
`AF_INET = 2` in the socket call.

### **Routing — packets leave `tun0` but no response**

The TUN device on FreeBSD is POINTOPOINT — needs a peer IP. The
plugin's `setup_if` writes:

```
ifconfig tt0 inet 172.16.219.2 172.16.219.1 netmask 255.255.255.0 mtu 1280 up
```

Verify:

```sh
ifconfig tt0
# inet 172.16.219.2 --> 172.16.219.1 netmask 0xffffff00
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

### **Server-side OPNsense Pass rule auto-created but blocking**

The plugin's `syncFirewallRule()` writes a `<filter><rule>` entry
into `/conf/config.xml` with `<plugin_managed>os-trusttunnel</plugin_managed>`.
If it appears under **Firewall → Rules → WAN** but traffic is still
blocked, double-check:

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

### **Auto-firewall rule duplicated on the secondary**

`syncFirewallRule()` walks `<filter>` looking for the marker
`<plugin_managed>os-trusttunnel</plugin_managed>` keyed by the stored
UUID. On the secondary, the UUID matches (sync'd from primary), so
there's no duplication. If you see two rules with the same UUID,
that's the bug — file an issue.

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
   running `tcpdump -i tt0 -nn icmp` while pinging.

## Diagnostics

A quick "is the tunnel working" health check:

```sh
# Server side
sockstat -l4 | grep ':443'
service trusttunnel_endpoint onestatus
grep trusttunnel-server /var/log/system/system_*.log | tail -20

# Client side
service trusttunnel_client onestatus
ifconfig tt0
netstat -ibn | grep tt0
grep trusttunnel-client /var/log/system/system_*.log | tail -20
```

If a step in this list fails, the corresponding section above has the
fix.
