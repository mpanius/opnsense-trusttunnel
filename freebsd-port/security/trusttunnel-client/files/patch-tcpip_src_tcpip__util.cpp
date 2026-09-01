--- tcpip/src/tcpip_util.cpp.orig
+++ tcpip/src/tcpip_util.cpp
@@ -1,6 +1,7 @@
 #include "tcpip_util.h"
 
 #ifndef _WIN32
+#include <arpa/inet.h>
 #include <unistd.h>
 #endif
 
