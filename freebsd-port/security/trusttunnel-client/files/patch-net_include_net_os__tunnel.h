--- net/include/net/os_tunnel.h.orig
+++ net/include/net/os_tunnel.h
@@ -241,6 +241,29 @@ private:
     bool m_sport_supported{false};
     std::string m_netns{};
 };
+#elif defined(__FreeBSD__)
+class VpnFreeBsdTunnel : public VpnOsTunnel {
+public:
+    VpnError init(const VpnOsTunnelSettings *settings) override;
+    void deinit() override;
+    evutil_socket_t get_fd() override;
+    std::string get_name() override;
+    bool get_system_dns_setup_success() const override;
+    ~VpnFreeBsdTunnel() override = default;
+
+private:
+    evutil_socket_t tun_open();
+    bool setup_if();
+    bool setup_routes();
+    void teardown_routes();
+    void setup_dns();
+
+    evutil_socket_t m_tun_fd{-1};
+    std::string m_tun_name{};
+    bool m_owned{false};
+    std::vector<std::string> m_ipv4_routes{};
+    std::vector<std::string> m_ipv6_routes{};
+};
 #elif __APPLE__ && !TARGET_OS_IPHONE
 class VpnMacTunnel : public VpnOsTunnel {
 public:
