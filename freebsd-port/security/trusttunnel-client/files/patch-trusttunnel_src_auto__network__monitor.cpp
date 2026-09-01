--- trusttunnel/src/auto_network_monitor.cpp.orig
+++ trusttunnel/src/auto_network_monitor.cpp
@@ -1,7 +1,7 @@
-#ifdef __APPLE__
+#if defined(__APPLE__) || defined(__FreeBSD__)
 #include <net/if.h>
 #include <netinet/in.h>
-#endif // __APPLE__
+#endif // __APPLE__ || __FreeBSD__
 
 #ifdef __linux__
 // clang-format off
@@ -46,13 +46,17 @@ static bool update_interface(std::string_view if_name) {
 }
 
 bool AutoNetworkMonitor::start() {
+    bool is_bound_if_override = !m_bound_if.empty();
+#ifdef __FreeBSD__
+    // NativeLibsCommon has no routing monitor backend for FreeBSD. Require an
+    // explicit interface and avoid constructing the unsupported monitor.
+    return is_bound_if_override && update_interface(m_bound_if);
+#else
     m_network_monitor_loop.reset(vpn_event_loop_create());
     m_network_monitor_loop_thread = std::thread([this]() {
         vpn_event_loop_run(m_network_monitor_loop.get());
     });
 
-    bool is_bound_if_override = !m_bound_if.empty();
-
     m_network_monitor = ag::utils::create_network_monitor(
             [this, is_bound_if_override](const std::string &if_name, bool is_connected) {
                 if (!is_bound_if_override) {
@@ -74,6 +78,7 @@ bool AutoNetworkMonitor::start() {
     });
 
     return true;
+#endif
 }
 
 void AutoNetworkMonitor::stop() {
