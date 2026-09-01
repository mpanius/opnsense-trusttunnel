--- net/src/ping.cpp.orig
+++ net/src/ping.cpp
@@ -729,6 +729,9 @@ void conn_protect_socket(PingConn *conn, SocketProtectEv
         int option = (event->peer->sa_family == AF_INET) ? IP_BOUND_IF : IPV6_BOUND_IF;
         int level = (event->peer->sa_family == AF_INET) ? IPPROTO_IP : IPPROTO_IPV6;
         int error = setsockopt(event->fd, level, option, &conn->bound_if, sizeof(conn->bound_if));
+#elif defined(__FreeBSD__)
+        int error = utils::bind_socket_to_if(event->fd, event->peer->sa_family, conn->bound_if_name.c_str())
+                        ? -1 : 0;
 #else  // #ifdef __MACH__
         int error = setsockopt(
                 event->fd, SOL_SOCKET, SO_BINDTODEVICE, conn->bound_if_name.data(), conn->bound_if_name.size());
