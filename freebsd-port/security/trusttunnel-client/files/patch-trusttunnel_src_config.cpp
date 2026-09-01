--- trusttunnel/src/config.cpp.orig
+++ trusttunnel/src/config.cpp
@@ -188,7 +188,7 @@ static std::optional<TrustTunnelConfig::TunListener> par
     }
 
     std::string bound_if;
-#if defined(_WIN32) || defined(__linux__) || defined(__APPLE__)
+#if defined(_WIN32) || defined(__linux__) || defined(__APPLE__) || defined(__FreeBSD__)
     bound_if = (*tun_config)["bound_if"].value_or<std::string>({});
 #else
     errlog(g_logger, "Outbound interface is not specified");
