--- core/src/upstream_multiplexer.cpp.orig
+++ core/src/upstream_multiplexer.cpp
@@ -208,5 +208,7 @@ void UpstreamMultiplexer::do_health_check() {
     if (info == nullptr) {
         log_mux(this, warn, "No health check has been started: there are no open sessions");
+        VpnError error = {VPN_EC_ERROR, "There are no open upstream sessions"};
+        this->handler.func(this->handler.arg, SERVER_EVENT_HEALTH_CHECK_ERROR, &error);
         return;
     }
 }
