--- net/src/os_tunnel.cpp.orig
+++ net/src/os_tunnel.cpp
@@ -1,5 +1,9 @@
 #include <vector>
 
+#ifndef _WIN32
+#include <unistd.h>
+#endif
+
 #include "common/utils.h"
 #include "net/network_manager.h"
 #include "net/os_tunnel.h"
@@ -278,6 +282,9 @@ std::unique_ptr<ag::VpnOsTunnel> ag::make_vpn_tunnel() {
 #elif __linux__ && !ANDROID
     std::unique_ptr<ag::VpnLinuxTunnel> tunnel{new ag::VpnLinuxTunnel{}};
     return tunnel;
+#elif defined(__FreeBSD__)
+    std::unique_ptr<ag::VpnFreeBsdTunnel> tunnel{new ag::VpnFreeBsdTunnel{}};
+    return tunnel;
 #else
     return nullptr;
 #endif
