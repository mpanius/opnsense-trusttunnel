--- common/include/vpn/platform.h.orig
+++ common/include/vpn/platform.h
@@ -173,6 +173,8 @@
 #define AG_PLATFORM "Android"
 #elif defined __linux__
 #define AG_PLATFORM "Linux"
+#elif defined __FreeBSD__
+#define AG_PLATFORM "FreeBSD"
 #endif
 
 #ifndef DEFAULT_CONNECTION_MEMORY_BUFFER_SIZE
