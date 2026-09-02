# FreeBSD Port Patches — TrustTunnel Client

## Текущий overlay: v1.1.5-rc.6 / FreeBSD 15

Upstream `v1.1.5-rc.6` поддерживает Windows, macOS и Linux, но не содержит
FreeBSD TUN backend. Текущий port overlay добавляет
`net/src/os_tunnel_freebsd.cpp` и подключает его через CMake и tunnel factory.

Backend открывает `/dev/tun` либо валидированное имя `tun<N>`, устанавливает
`TUNSIFHEAD=0`, получает фактическое имя через `TUNGIFNAME` с полным
`struct ifreq`, настраивает IPv4 POINTOPOINT, MTU и маршруты. В create-mode
имя должно быть пустым или свободным `tun<N>`; при остановке backend удаляет
принадлежащий ему интерфейс. В attach-mode он сохраняет адреса, MTU и lifecycle
existing TUN, переключает packet-header mode и снимает только собственные
managed routes. IPv6 и
автоматическая замена системного DNS не реализованы: DNS должен настраиваться
средствами OPNsense, `change_system_dns` — оставаться `false`.

Минимальная проверка backend на FreeBSD запускается от root:

```sh
BOUND_IF=vtnet0  # замените на фактический исходящий интерфейс узла
sh /path/to/opnsense-trusttunnel/tests/freebsd_client_tun_smoke.sh \
  /usr/local/sbin/trusttunnel_client "$BOUND_IF"
```

Smoke проверяет create/cleanup, MTU 1350, отказ занять existing TUN без
attach-mode и attach-mode route cleanup, но не
реальный трафик. Локальный E2E на двух изолированных OPNsense 26.7.3_8 VM
(FreeBSD ABI 1501000) дополнительно подтвердил
маркеры `VPN_SS_CONNECTED` и успешного подключения к endpoint, маршрут
`1.1.1.1 -> tun0`, TCP HTTP 301, UDP DNS, двусторонний рост счётчиков без
ошибок и cleanup. Production validation этим не заменяется.

### Восстановление пула HTTP/2

`patch-core_src_upstream__multiplexer.cpp` закрывает отдельный upstream-дефект:
`do_health_check()` раньше возвращался без event, когда установленных сессий
уже нет, но multiplexer содержит только открывающиеся replacement-сессии. Пул
оставался непустым и не поднимал `SERVER_EVENT_SESSION_CLOSED`, поэтому Client
мог сохранять состояние connected и бесконечно заменять нерабочие upstream.

Patch поднимает `SERVER_EVENT_HEALTH_CHECK_ERROR` с сообщением `There are no
open upstream sessions`. Существующая Client state machine после этого входит
в recovery; повторный ping/reselection выполняется по её штатным правилам.
Связанный
`patch-core_test_test__upstream__multiplexer.cpp` создаёт ровно это состояние и
проверяет event; полный `test_upstream_multiplexer` обязан оставаться зелёным.
Transport, pool size и значения timeout не меняются.

## Архив: v1.1.4 / FreeBSD 14.3

> Этот документ сохраняет историю портирования v1.1.4 на FreeBSD 14.3.
> Текущая сборка v1.1.5-rc.6 использует другой набор overlay patches из
> `freebsd-port/security/trusttunnel-client/files/` и `freebsd-port/conan/`.
> Описанные ниже patches не являются описанием текущего backend; используйте
> их только для разбора исторических сборок.

Кумулятивный список всех patches, применённых для сборки и запуска
`trusttunnel_client` (из репо TrustTunnel/TrustTunnelClient) на
FreeBSD 14.3-RELEASE-p12 (OPNsense 26.1.8_5).

Все patches reproducible через scripted application к двум upstream
репозиториям + одному conan recipe set. Конечный результат:

```
23.05.2026 06:35:07 VPNCORE raise_state: [0] VPN_SS_CONNECTED ✓
23.05.2026 06:35:07 TRUSTTUNNEL_CLIENT_APP operator(): Successfully connected to endpoint ✓
```

## Group A — Conan dependency unblocks

### A1. `conanfile.py` — bump dns-libs pin

```diff
-self.requires("dns-libs/2.8.51@adguard/oss", transitive_headers=True)
+self.requires("dns-libs/2.8.52@adguard/oss", transitive_headers=True)
```

**Reason:** v2.8.51 is an internal AdGuard pin not published to the public
DnsLibs repository. v2.8.52 is the closest existing tag.

### A2. Conan profile — system cmake

```ini
# ~/.conan2/profiles/default
[platform_tool_requires]
cmake/3.31.12
```

**Reason:** Conan recipe `cmake/3.31.12` has prebuilt binary entries for
Linux/Macos/Windows but **no FreeBSD entry** → `KeyError: 'FreeBSD'` when
attempting to fetch sources. `platform_tool_requires` tells Conan to use
the system-installed cmake instead.

### A3. quiche recipe `conanfile.py` — FreeBSD cargo target

Add elif branch in the build() method:

```python
elif os == "FreeBSD":
    target = "%s-unknown-freebsd" % ("aarch64" if arch == "armv8" else arch)
    cargo_args = "build %s --target %s" % (cargo_build_type, target)
```

**Reason:** quiche recipe explicitly rejected FreeBSD via
`raise ConanInvalidConfiguration("Unsupported OS: %s" % os)`.

## Group B — `native_libs_common` (AdGuard NativeLibsCommon) patches

Все эти patches применяются через `def source()` extension в
`conanfile.py` после `git checkout` (`replace_in_file` calls). Recipe
re-exported with new revision after edit.

### B1. `socket_address.h` — sin_len + sin_family byte layout

```diff
-#ifdef __APPLE__
+#if defined(__APPLE__) || defined(__FreeBSD__)
     uint8_t sa_len;
     uint8_t sa_family;
 #else
     uint16_t sa_family;
 #endif
```

**Reason:** FreeBSD's `struct sockaddr_in` has the same byte layout as
macOS: `uint8_t sin_len; uint8_t sin_family;` (not `uint16_t sin_family`
like Linux). Without this fix, casting `SocketAddressStorage*` to
`sockaddr_in*` and reading `sa_family` returns garbage, causing
`socket(family=47)` errors throughout the stack. **The single most
critical patch.**

### B2. `socket_address.h` — `#include <netinet/in.h>`

```diff
 #include <event2/util.h>
+#include <netinet/in.h>
```

**Reason:** `sockaddr_in6` is referenced in `SocketAddressStorage`
declaration but not transitively included on FreeBSD via libevent.

### B3. `net_utils.cpp` — bind_to_if Linux/FreeBSD share path

```diff
-#if defined(__linux__)
+#if defined(__linux__) || defined(__FreeBSD__)
     char buf[IF_NAMESIZE];
     const char *name = if_indextoname(if_index, buf);
```

**Reason:** Both Linux and FreeBSD have POSIX `if_indextoname`. macOS
uses `IP_BOUND_IF` socket option which FreeBSD lacks.

### B4. `net_utils.cpp` — SO_BINDTODEVICE replacement on FreeBSD

```diff
+#if defined(__FreeBSD__)
+    struct ifaddrs *_ifs = nullptr; int ret = -1;
+    if (getifaddrs(&_ifs) == 0) {
+        for (auto *ifa = _ifs; ifa; ifa = ifa->ifa_next) {
+            if (ifa->ifa_name && strcmp(ifa->ifa_name, if_name) == 0 &&
+                ifa->ifa_addr && ifa->ifa_addr->sa_family == family) {
+                socklen_t _len = (family == AF_INET) ?
+                    sizeof(sockaddr_in) : sizeof(sockaddr_in6);
+                ret = ::bind(fd, ifa->ifa_addr, _len);
+                break;
+            }
+        }
+        freeifaddrs(_ifs);
+    }
+#else
     int ret = setsockopt(fd, SOL_SOCKET, SO_BINDTODEVICE,
                          if_name, strlen(if_name));
+#endif
```

**Reason:** FreeBSD has no `SO_BINDTODEVICE` (Linux-only). Bind to the
interface's IP via `getifaddrs()` instead (mirrors upstream PR #28 quiche
fix).

### B5. `net_utils.cpp` — `<ifaddrs.h>` include

```diff
+#include <ifaddrs.h>
+#include <string.h>
 #include "common/net_utils.h"
```

### B6. `file.cpp` — extend Linux/MACH guards

Two occurrences:

```diff
-#if defined(__linux__) || defined(__LINUX__) || defined(__MACH__)
+#if defined(__linux__) || defined(__LINUX__) || defined(__MACH__) || defined(__FreeBSD__)
```

**Reason:** Generic POSIX file operations (`#include <unistd.h>`,
`is_valid()`, etc.) — FreeBSD has identical POSIX semantics, not Win32.

### B7. `time_utils.cpp` — `tm_gmtoff` instead of `extern long timezone`

```diff
+#elif defined(__FreeBSD__)
+    tzset();
+    time_t _now = time(nullptr);
+    struct tm _lt = {};
+    localtime_r(&_now, &_lt);
+    return -_lt.tm_gmtoff;
 #else
     tzset();
     return timezone;
 #endif
```

**Reason:** FreeBSD has no global `timezone` variable (Linux glibc
specific). `tm_gmtoff` is POSIX-portable.

### B8. `cidr_range.h` — `<sys/socket.h>` include

```diff
 #pragma once
+#include <sys/socket.h>
+#include <netinet/in.h>
```

**Reason:** `AF_INET` / `AF_INET6` macros used but not transitively
included on FreeBSD.

### B9. `utils.cpp` — gettid() via pthread_getthreadid_np

```diff
+#ifdef __FreeBSD__
+#include <pthread_np.h>
+uint32_t utils::gettid(void) { return (uint32_t) pthread_getthreadid_np(); }
+#endif
```

**Reason:** Linux `syscall(SYS_gettid)` and macOS `pthread_threadid_np`
unavailable on FreeBSD. `pthread_getthreadid_np` is the FreeBSD
equivalent.

### B10. `package_info()` — exclude `resolv` system library on FreeBSD

```diff
-if self.settings.os != "Android":
-    self.cpp_info.system_libs = ["resolv"]
+if self.settings.os not in ("Android", "FreeBSD"):
+    self.cpp_info.system_libs = ["resolv"]
```

**Reason:** FreeBSD's libc includes `res_*` functions natively; there is
no separate `libresolv.so`. Linker fails with `unable to find library
-lresolv`.

### B11. `network_monitor.cpp::is_running()` — FreeBSD return false

```diff
 #ifdef _WIN32
     return true;
 #endif // _WIN32
+#ifdef __FreeBSD__
+    return false;
+#endif
 }
```

**Reason:** Function had no return statement when neither Linux/macOS/Win
macros defined → undefined behavior (the actual reason for the SIGBUS
crash in `~NetworkMonitorImpl::stop()`).

## Group C — `dns-libs` (AdGuard DnsLibs) patches

Applied via `conanfile.py def source()` extension, same pattern as
native_libs_common.

### C1. `common/sys.cpp` — extend Linux/MACH guards

```diff
-#if defined(__linux__) || defined(__LINUX__) || defined(__MACH__)
+#if defined(__linux__) || defined(__LINUX__) || defined(__MACH__) || defined(__FreeBSD__)
 #include <sys/resource.h>
```

```diff
-#if defined(__linux__) || defined(__LINUX__) || defined(__MACH__)
+#if defined(__linux__) || defined(__LINUX__) || defined(__MACH__) || defined(__FreeBSD__)
 
 int error_code() {
```

**Reason:** `getrusage()` works on FreeBSD same as Linux/macOS.

## Group D — TrustTunnelClient source patches

These edit files directly in the repo (would be a PR to
TrustTunnel/TrustTunnelClient upstream).

### D1. `trusttunnel/src/config.cpp::parse_tun_listener_config` — allow FreeBSD

```diff
-#if defined(_WIN32) || defined(__linux__) || defined(__APPLE__)
+#if defined(_WIN32) || defined(__linux__) || defined(__APPLE__) || defined(__FreeBSD__)
     bound_if = (*tun_config)["bound_if"].value_or<std::string>({});
```

### D2. `common/include/vpn/platform.h::AG_PLATFORM` — FreeBSD branch

```diff
 #elif defined __linux__
 #define AG_PLATFORM "Linux"
+#elif defined __FreeBSD__
+#define AG_PLATFORM "FreeBSD"
 #endif
```

### D3. `common/src/platform.cpp` — extend MACH/linux guard

```diff
-#if defined __MACH__ || defined __linux__
+#if defined __MACH__ || defined __linux__ || defined __FreeBSD__
 
 int last_error() {
     return errno;
 }
```

### D4. `net/src/tcp_socket.cpp` — FreeBSD stubs for VPN connection stats

```diff
 #endif // __linux__
 
+#ifdef __FreeBSD__
+TcpFlowCtrlInfo tcp_socket_flow_control_info(const TcpSocket *socket) {
+    (void) socket;
+    return {};
+}
+VpnConnectionStats tcp_socket_get_stats(const TcpSocket *socket) {
+    (void) socket;
+    return {};
+}
+#endif // __FreeBSD__
+
 #ifdef __MACH__
```

**Reason:** Linux uses `TCP_INFO` socket option; macOS uses
`TCP_CONNECTION_INFO`. FreeBSD has its own (`TCP_INFO`-compatible) but
stubs are sufficient for v1 — VPN connection stats are advisory.

### D5. `net/src/ping.cpp` — bind_to_if via getifaddrs (mirror B4)

```diff
+#elif defined(__FreeBSD__)
+        int error = -1;
+        struct ifaddrs *_ifs = nullptr;
+        if (getifaddrs(&_ifs) == 0) {
+            for (auto *ifa = _ifs; ifa; ifa = ifa->ifa_next) {
+                if (ifa->ifa_name && strncmp(ifa->ifa_name,
+                        conn->bound_if_name.data(),
+                        conn->bound_if_name.size()) == 0
+                    && ifa->ifa_addr && ifa->ifa_addr->sa_family ==
+                                        event->peer->sa_family) {
+                    socklen_t _len = (event->peer->sa_family == AF_INET) ?
+                        sizeof(sockaddr_in) : sizeof(sockaddr_in6);
+                    error = ::bind(event->fd, ifa->ifa_addr, _len);
+                    break;
+                }
+            }
+            freeifaddrs(_ifs);
+        }
+#else
         int error = setsockopt(event->fd, SOL_SOCKET, SO_BINDTODEVICE,
             conn->bound_if_name.data(), conn->bound_if_name.size());
 #endif
```

### D6. `net/src/os_tunnel.cpp::make_vpn_tunnel()` — FreeBSD factory

```diff
 #elif __linux__ && !ANDROID
     std::unique_ptr<ag::VpnLinuxTunnel> tunnel{new ag::VpnLinuxTunnel{}};
     return tunnel;
+#elif __FreeBSD__
+    std::unique_ptr<ag::VpnFreeBsdTunnel> tunnel{new ag::VpnFreeBsdTunnel{}};
+    return tunnel;
 #else
     return nullptr;
 #endif
```

### D7. `net/CMakeLists.txt` — compile FreeBSD source

```diff
 elseif(CMAKE_SYSTEM_NAME STREQUAL Linux)
     list(APPEND SOURCE_FILES ${NET_SOURCE_DIR}/os_tunnel_linux.cpp)
+elseif(CMAKE_SYSTEM_NAME STREQUAL FreeBSD)
+    list(APPEND SOURCE_FILES ${NET_SOURCE_DIR}/os_tunnel_freebsd.cpp)
 endif()
```

### D8. `net/CMakeLists.txt` — skip resolv on FreeBSD

```diff
 if (NOT ANDROID)
+    if(NOT CMAKE_SYSTEM_NAME MATCHES "FreeBSD")
         target_link_libraries(vpnlibs_net resolv)
+    endif()
 endif()
```

### D9. `net/src/os_tunnel.h` — VpnFreeBsdTunnel class declaration

```cpp
#if defined(__FreeBSD__)
class VpnFreeBsdTunnel : public VpnOsTunnel {
public:
    VpnError init(const VpnOsTunnelSettings *settings) override;
    evutil_socket_t get_fd() override;
    std::string get_name() override;
    void deinit() override;
    bool get_system_dns_setup_success() const override;
    ~VpnFreeBsdTunnel() override = default;
protected:
    evutil_socket_t tun_open();
    void setup_if();
    void setup_dns();
    bool setup_routes();
private:
    evutil_socket_t m_tun_fd{-1};
    std::string m_tun_name{};
};
WIN_EXPORT void *vpn_freebsd_tunnel_create(VpnOsTunnelSettings *settings);
WIN_EXPORT void vpn_freebsd_tunnel_destroy(void *fbsd_tunnel);
#endif
```

### D10. `net/src/os_tunnel_freebsd.cpp` — NEW FILE (200+ lines)

See `freebsd-patches/os_tunnel_freebsd.cpp` (the actual implementation).
Key points:

- `/dev/tun` auto-clone → fd, ioctl `TUNGIFNAME` (with **`struct ifreq`**,
  not `char[IFNAMSIZ]` — kernel writes the full struct, 16-char buffer
  causes stack overflow corrupting `this` register)
- `TUNSIFHEAD=0` for Linux-compatible raw IP packets
- `setup_if`: `ifconfig tunN inet … mtu … up` + `inet6 …`
- `setup_routes`: `route -q add -net X.X.X.X/N -interface tunN`
- `setup_dns`: prepend `nameserver` entries to `/etc/resolv.conf`,
  preserve existing entries

### D11. `core/src/dns_handler.cpp::start_system_dns_proxy()` — FreeBSD skip

```cpp
bool ag::DnsHandler::start_system_dns_proxy() {
#if defined(__FreeBSD__)
    log_handler(this, info, "FreeBSD: system DNS proxy disabled "
                            "(use upstream DNS via [endpoint].dns_upstreams)");
    return true;
#endif
    // ... existing Linux/macOS code
}
```

**Reason:** `dns_manager_get_system_servers` has no FreeBSD implementation
yet; without this skip, returns garbage that triggers
`Failed to create socket: Address family not supported (47)`.

### D12. `trusttunnel/src/auto_network_monitor.cpp` — skip NetworkMonitor

```cpp
#if defined(__FreeBSD__)
if (is_bound_if_override) {
    return update_interface(m_bound_if);
}
#endif
m_network_monitor = ag::utils::create_network_monitor(...);
```

**Reason:** `NetworkMonitorImpl` has no FreeBSD branch (no equivalent to
Linux netlink or macOS path_monitor). Crash in destructor's `stop()`
when garbage members accessed. Skip when user provides explicit
`bound_if` (override path).

### D13. `trusttunnel/src/auto_network_monitor.cpp` — `<net/if.h>` for if_nametoindex

```diff
-#ifdef __APPLE__
+#if defined(__APPLE__) || defined(__FreeBSD__)
 #include <net/if.h>
 #include <netinet/in.h>
 #endif
```

### D14. `net/src/os_tunnel.cpp` (already covered in D6) — factory

### D15. `net/src/os_tunnel.cpp::ping.cpp` — `<ifaddrs.h>` include (covered)

### D16. `net/src/os_tunnel.cpp os_tunnel.cpp` — `<unistd.h>` for geteuid

```diff
+#include <unistd.h>
 // ...
 if (geteuid() != 0) { ... }
```

### D17. `tcpip/src/tcpip_util.cpp` — `<arpa/inet.h>` for `inet_ntop`

```diff
+#include <arpa/inet.h>
```

## v1.0.1 Polish Backlog

Three remaining cosmetic issues for full data-plane operation (v1.0.1):

1. **`setup_if` POINTOPOINT syntax** — current `ifconfig {} inet {} mtu {}
   up` doesn't apply IPv4 for tun POINTOPOINT interface; needs `inet LOCAL
   PEER netmask MASK` form.
2. **`setup_dns` sh-quoting** — `printf '%s'` with multi-line content
   triggers `sh: nameserver: not found` warnings. Switch to heredoc or
   tempfile + atomic mv.
3. **SOCKS5 listener auth** — TrustTunnel's auto-spawned SOCKS5 listener
   at 127.0.0.1:9972 requires authentication; client config needs
   `[listener.socks] username = "" password = ""` no-auth mode, or
   documented credentials.

## Upstream PRs to Open

| Repo | Patch group | Lines |
|---|---|---|
| AdGuardTeam/NativeLibsCommon | B1–B11 | ~80 |
| AdGuardTeam/DnsLibs | C1 | ~5 |
| TrustTunnel/TrustTunnelClient | D1–D17 + os_tunnel_freebsd.cpp | ~250 |

PRs reference issue: AdGuard's existing PR #28 (quiche FreeBSD support)
was merged Feb 2026 — this is the natural follow-up for the **C++ side**
of the stack.
