--- net/src/os_tunnel.cpp.orig
+++ net/src/os_tunnel.cpp
@@ -1,4 +1,8 @@
 #include <vector>
 
+#ifndef _WIN32
+#include <unistd.h>
+#endif
+
 #include "common/utils.h"
 #include "net/network_manager.h"
 #include "net/os_tunnel.h"
