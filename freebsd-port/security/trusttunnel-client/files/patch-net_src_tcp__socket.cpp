--- net/src/tcp_socket.cpp.orig
+++ net/src/tcp_socket.cpp
@@ -822,6 +822,17 @@ VpnConnectionStats tcp_socket_get_stats(const TcpSocket 
 
 #endif // __linux__
 
+#ifdef __FreeBSD__
+TcpFlowCtrlInfo tcp_socket_flow_control_info(const TcpSocket *socket) {
+    return {tcp_socket_available_to_write(socket), DEFAULT_SEND_WINDOW_SIZE};
+}
+
+VpnConnectionStats tcp_socket_get_stats(const TcpSocket *socket) {
+    (void) socket;
+    return {};
+}
+#endif // __FreeBSD__
+
 #ifdef __MACH__
 
 static inline int get_tcp_connection_info(evutil_socket_t fd, struct tcp_connection_info *ti) {
