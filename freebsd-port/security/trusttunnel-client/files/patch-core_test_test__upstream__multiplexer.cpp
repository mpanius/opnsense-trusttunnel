--- core/test/test_upstream_multiplexer.cpp.orig
+++ core/test/test_upstream_multiplexer.cpp
@@ -65,3 +65,4 @@ protected:
     DeclPtr<VpnEventLoop, &vpn_event_loop_destroy> ev_loop{vpn_event_loop_create()};
     VpnClient vpn;
     int events = 0;
+    std::optional<VpnError> health_check_error;
@@ -69,4 +70,7 @@ protected:
-    static void upstream_handler(void *arg, ServerEvent what, void *) {
+    static void upstream_handler(void *arg, ServerEvent what, void *data) {
         auto *test = (UpstreamMuxTest *) arg;
         test->events |= 1 << what;
+        if (what == SERVER_EVENT_HEALTH_CHECK_ERROR && data != nullptr) {
+            test->health_check_error = *(VpnError *) data;
+        }
     }
@@ -378,2 +382,31 @@ TEST_F(UpstreamMuxTest, FatalErrorOnSomeUpstream) {
+// Check that a health check reports a failure when all established sessions
+// have disappeared but replacement upstreams are still opening. Otherwise
+// the parent client remains connected and keeps replenishing the dead pool.
+TEST_F(UpstreamMuxTest, HealthCheckFailsWithOnlyOpeningUpstreams) {
+    int opened_upstream_id = g_upstreams.begin()->first;
+
+    for (size_t i = 0; i <= UpstreamMultiplexer::NEW_UPSTREAM_CONNECTIONS_NUM_THRESHOLD; ++i) {
+        ASSERT_NE(initiate_connection(), NON_ID);
+    }
+    ASSERT_EQ(g_upstreams.size(), 2);
+
+    ASSERT_NO_FATAL_FAILURE(notify_session_closed(opened_upstream_id));
+    run_event_loop_once();
+    g_upstreams.erase(opened_upstream_id);
+
+    ASSERT_EQ(g_upstreams.size(), 1);
+    ASSERT_FALSE(is_raised(SERVER_EVENT_SESSION_CLOSED)) << std::hex << events;
+
+    events = 0;
+    g_health_checking_upstream_id.reset();
+    vpn.endpoint_upstream->do_health_check();
+
+    ASSERT_TRUE(is_raised(SERVER_EVENT_HEALTH_CHECK_ERROR)) << std::hex << events;
+    ASSERT_TRUE(health_check_error.has_value());
+    EXPECT_EQ(health_check_error->code, VPN_EC_ERROR);
+    EXPECT_EQ(health_check_error->text, "There are no open upstream sessions");
+    ASSERT_FALSE(g_health_checking_upstream_id.has_value());
+}
+
 // Check that new upstreams appear instead of closed ones
 TEST_F(UpstreamMuxTest, NewUpstreamsInsteadClosed) {
