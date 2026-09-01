--- common/src/platform.cpp.orig
+++ common/src/platform.cpp
@@ -78,7 +78,7 @@ bool is_windows_11_or_later() {
 
 #endif //_WIN32
 
-#if defined __MACH__ || defined __linux__
+#if defined __MACH__ || defined __linux__ || defined __FreeBSD__
 
 int last_error() {
     return errno;
