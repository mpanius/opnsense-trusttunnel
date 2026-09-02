--- /dev/null
+++ net/src/os_tunnel_freebsd.cpp
@@ -0,0 +1,257 @@
+#include "net/os_tunnel.h"
+
+#include <arpa/inet.h>
+#include <cerrno>
+#include <cstring>
+#include <fcntl.h>
+#include <net/if.h>
+#include <net/if_tun.h>
+#include <sys/ioctl.h>
+#include <unistd.h>
+
+#include "common/utils.h"
+
+static const ag::Logger logger("OS_TUNNEL_FREEBSD");
+
+static bool sys_cmd_bool(std::string cmd) {
+    cmd += " 2>&1";
+    dbglog(logger, "{} {}", (geteuid() == 0) ? '#' : '$', cmd);
+    auto result = ag::exec_with_output(cmd);
+    if (result.has_error()) {
+        dbglog(logger, "{}", result.error()->str());
+        return false;
+    }
+    if (!result.value().output.empty()) {
+        dbglog(logger, "{}", ag::utils::rtrim(result.value().output));
+    }
+    if (result.value().status != 0) {
+        dbglog(logger, "Exit code: {}", result.value().status);
+        return false;
+    }
+    return true;
+}
+
+static bool is_valid_tun_name(std::string_view name) {
+    if (!name.starts_with("tun") || name.size() == 3) {
+        return false;
+    }
+    for (char ch : name.substr(3)) {
+        if (ch < '0' || ch > '9') {
+            return false;
+        }
+    }
+    return name.size() < IFNAMSIZ;
+}
+
+ag::VpnError ag::VpnFreeBsdTunnel::init(const ag::VpnOsTunnelSettings *settings) {
+    init_settings(settings);
+    bool managed_routing = m_settings->included_routes.size > 0;
+    infolog(logger, "TUN mode: {}{}", m_settings->use_existing ? "attach" : "create",
+            managed_routing ? "" : " (routes unmanaged)");
+    if (tun_open() == -1) {
+        return {-1, "Failed to init FreeBSD tunnel"};
+    }
+    if (!setup_if()) {
+        deinit();
+        return {-1, "Failed to configure FreeBSD tunnel"};
+    }
+    mark_tunnel_active();
+    if (managed_routing && !setup_routes()) {
+        deinit();
+        return {-1, "Unable to setup routes for FreeBSD tunnel"};
+    }
+    setup_dns();
+    return {};
+}
+
+void ag::VpnFreeBsdTunnel::deinit() {
+    std::string interface = m_tun_name;
+    teardown_routes();
+    if (m_tun_fd >= 0) {
+        close(m_tun_fd);
+        m_tun_fd = -1;
+    }
+    if (m_owned && !interface.empty()) {
+        if (!sys_cmd_bool(AG_FMT("/sbin/ifconfig {} destroy",
+                    ag::utils::escape_argument_for_shell(interface)))) {
+            warnlog(logger, "Failed to destroy owned interface {}", interface);
+        }
+    }
+    m_owned = false;
+    m_tun_name.clear();
+    m_system_dns_setup_success = false;
+    clear_tunnel_active();
+}
+
+std::string ag::VpnFreeBsdTunnel::get_name() {
+    return m_tun_name;
+}
+
+evutil_socket_t ag::VpnFreeBsdTunnel::get_fd() {
+    return m_tun_fd;
+}
+
+bool ag::VpnFreeBsdTunnel::get_system_dns_setup_success() const {
+    return m_system_dns_setup_success;
+}
+
+evutil_socket_t ag::VpnFreeBsdTunnel::tun_open() {
+    std::string_view requested = ag::utils::safe_string_view(m_settings->device_name);
+    std::string path = "/dev/tun";
+    if (m_settings->use_existing && requested.empty()) {
+        errlog(logger, "use_existing requires device_name");
+        return -1;
+    }
+    if (!requested.empty()) {
+        if (!is_valid_tun_name(requested)) {
+            errlog(logger, "Malformed FreeBSD device_name '{}'; expected tun<N>", requested);
+            return -1;
+        }
+        std::string requested_name{requested};
+        bool exists = if_nametoindex(requested_name.c_str()) != 0;
+        if (m_settings->use_existing && !exists) {
+            errlog(logger, "Device {} does not exist (use_existing = true)", requested);
+            return -1;
+        }
+        if (!m_settings->use_existing && exists) {
+            errlog(logger, "Device {} already exists; refusing to create", requested);
+            return -1;
+        }
+        path = AG_FMT("/dev/{}", requested);
+    }
+
+    int fd = open(path.c_str(), O_RDWR | O_CLOEXEC);
+    if (fd < 0) {
+        errlog(logger, "Failed to open {}: ({}) {}", path, errno, strerror(errno));
+        return -1;
+    }
+
+    int ifhead = 0;
+    if (ioctl(fd, TUNSIFHEAD, &ifhead) < 0) {
+        errlog(logger, "TUNSIFHEAD failed: ({}) {}", errno, strerror(errno));
+        close(fd);
+        return -1;
+    }
+
+    struct ifreq ifr {};
+    if (ioctl(fd, TUNGIFNAME, &ifr) < 0) {
+        errlog(logger, "TUNGIFNAME failed: ({}) {}", errno, strerror(errno));
+        close(fd);
+        return -1;
+    }
+
+    m_tun_fd = fd;
+    m_tun_name = ifr.ifr_name;
+    m_owned = !m_settings->use_existing;
+    m_if_index = if_nametoindex(m_tun_name.c_str());
+    if (m_if_index == 0) {
+        errlog(logger, "if_nametoindex({}) failed: ({}) {}", m_tun_name, errno, strerror(errno));
+        close(fd);
+        m_tun_fd = -1;
+        return -1;
+    }
+    infolog(logger, "Device {} opened", m_tun_name);
+    return fd;
+}
+
+bool ag::VpnFreeBsdTunnel::setup_if() {
+    if (m_settings->use_existing) {
+        m_ipv6_available = false;
+        return true;
+    }
+
+    auto ipv4 = tunnel_utils::get_address_for_index(m_settings->ipv4_address, m_if_index);
+    std::string local = ipv4.get_address_as_string();
+
+    struct in_addr local_addr {};
+    if (inet_pton(AF_INET, local.c_str(), &local_addr) != 1) {
+        errlog(logger, "Invalid generated IPv4 address {}", local);
+        return false;
+    }
+    uint32_t host = ntohl(local_addr.s_addr);
+    uint32_t peer_host = (host & 0xffffff00U) | ((host & 0xffU) == 1U ? 2U : 1U);
+    struct in_addr peer_addr {.s_addr = htonl(peer_host)};
+    char peer_buffer[INET_ADDRSTRLEN] {};
+    if (inet_ntop(AF_INET, &peer_addr, peer_buffer, sizeof(peer_buffer)) == nullptr) {
+        errlog(logger, "Failed to format peer IPv4 address: ({}) {}", errno, strerror(errno));
+        return false;
+    }
+
+    uint32_t prefix = ipv4.get_prefix_len();
+    uint32_t mask_host = prefix == 0 ? 0 : (0xffffffffU << (32U - prefix));
+    struct in_addr mask_addr {.s_addr = htonl(mask_host)};
+    char mask_buffer[INET_ADDRSTRLEN] {};
+    if (inet_ntop(AF_INET, &mask_addr, mask_buffer, sizeof(mask_buffer)) == nullptr) {
+        errlog(logger, "Failed to format IPv4 netmask: ({}) {}", errno, strerror(errno));
+        return false;
+    }
+
+    std::string interface = ag::utils::escape_argument_for_shell(m_tun_name);
+    if (!sys_cmd_bool(AG_FMT("/sbin/ifconfig {} inet {} {} netmask {} mtu {} up", interface,
+                ag::utils::escape_argument_for_shell(local), ag::utils::escape_argument_for_shell(peer_buffer),
+                ag::utils::escape_argument_for_shell(mask_buffer), m_settings->mtu))) {
+        return false;
+    }
+    m_ipv6_available = false;
+    return true;
+}
+
+bool ag::VpnFreeBsdTunnel::setup_routes() {
+    std::vector<ag::CidrRange> ipv4_routes;
+    std::vector<ag::CidrRange> ipv6_routes;
+    ag::tunnel_utils::get_setup_routes(
+            ipv4_routes, ipv6_routes, m_settings->included_routes, m_settings->excluded_routes);
+    if (!m_ipv6_available) {
+        ipv6_routes.clear();
+    }
+    std::string interface = ag::utils::escape_argument_for_shell(m_tun_name);
+    for (const auto &route : ipv4_routes) {
+        if (!sys_cmd_bool(AG_FMT("/sbin/route -q add -net {} -interface {}",
+                    ag::utils::escape_argument_for_shell(route.to_string()), interface))) {
+            return false;
+        }
+        m_ipv4_routes.push_back(route.to_string());
+    }
+    for (const auto &route : ipv6_routes) {
+        if (!sys_cmd_bool(AG_FMT("/sbin/route -q add -inet6 {} -interface {}",
+                    ag::utils::escape_argument_for_shell(route.to_string()), interface))) {
+            return false;
+        }
+        m_ipv6_routes.push_back(route.to_string());
+    }
+    return true;
+}
+
+void ag::VpnFreeBsdTunnel::teardown_routes() {
+    if (m_tun_name.empty()) {
+        m_ipv4_routes.clear();
+        m_ipv6_routes.clear();
+        return;
+    }
+
+    std::string interface = ag::utils::escape_argument_for_shell(m_tun_name);
+    for (auto route = m_ipv4_routes.rbegin(); route != m_ipv4_routes.rend(); ++route) {
+        if (!sys_cmd_bool(AG_FMT("/sbin/route -q delete -net {} -interface {}",
+                    ag::utils::escape_argument_for_shell(*route), interface))) {
+            warnlog(logger, "Failed to remove owned IPv4 route {}", *route);
+        }
+    }
+    for (auto route = m_ipv6_routes.rbegin(); route != m_ipv6_routes.rend(); ++route) {
+        if (!sys_cmd_bool(AG_FMT("/sbin/route -q delete -inet6 {} -interface {}",
+                    ag::utils::escape_argument_for_shell(*route), interface))) {
+            warnlog(logger, "Failed to remove owned IPv6 route {}", *route);
+        }
+    }
+    m_ipv4_routes.clear();
+    m_ipv6_routes.clear();
+}
+
+void ag::VpnFreeBsdTunnel::setup_dns() {
+    if (m_settings->dns_servers.size == 0) {
+        m_system_dns_setup_success = true;
+        return;
+    }
+    warnlog(logger,
+            "Automatic system DNS replacement is disabled on FreeBSD; configure DNS through OPNsense instead");
+    m_system_dns_setup_success = false;
+}
