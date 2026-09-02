import unittest
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "net/os-trusttunnel-client/src"


class ClientPluginContractTest(unittest.TestCase):
    def test_model_exposes_freebsd_tun_contract(self):
        model = ET.parse(
            PLUGIN
            / "opnsense/mvc/app/models/OPNsense/TrustTunnelClient/TrustTunnelClient.xml"
        ).getroot()
        client = model.find("./items/client")
        self.assertIsNotNone(client)
        self.assertEqual(model.findtext("version"), "2.1.0")
        self.assertEqual(client.findtext("mode/default"), "general")
        self.assertEqual(client.findtext("mtu_size/default"), "1350")
        self.assertEqual(client.findtext("change_system_dns/default"), "0")
        self.assertEqual(client.findtext("bound_if/default"), "")
        self.assertEqual(client.findtext("bound_if/Required"), "Y")
        self.assertEqual(client.find("active_server").get("type"), "TextField")
        self.assertIn("{36}", client.findtext("active_server/Mask"))
        for field in (
            "tun_interface",
            "allowed_destinations",
            "excluded_destinations",
            "use_existing",
            "mtu_size",
            "change_system_dns",
            "bound_if",
        ):
            self.assertIsNotNone(client.find(field), field)

    def test_form_ids_match_model_mount(self):
        form = ET.parse(
            PLUGIN
            / "opnsense/mvc/app/controllers/OPNsense/TrustTunnelClient/forms/client.xml"
        ).getroot()
        ids = [field.findtext("id") for field in form.findall("field")]
        self.assertTrue(ids)
        self.assertTrue(all(item.startswith("trusttunnelclient.client.") for item in ids))
        help_by_id = {
            field.findtext("id"): field.findtext("help", "")
            for field in form.findall("field")
        }
        self.assertIn(
            "traffic policy",
            help_by_id["trusttunnelclient.client.mode"].lower(),
        )
        routes_help = help_by_id[
            "trusttunnelclient.client.allowed_destinations"
        ].lower()
        self.assertIn("both modes", routes_help)
        self.assertIn("empty", routes_help)

    def test_reconfigure_preflights_tun_ownership(self):
        controller = (
            PLUGIN
            / "opnsense/mvc/app/controllers/OPNsense/TrustTunnelClient/Api/ClientController.php"
        ).read_text(encoding="utf-8")
        self.assertNotIn("tun_interface_warning", controller)
        self.assertIn("validateRuntimeInterfaces", controller)
        self.assertIn("use_existing", controller)
        self.assertIn("/sbin/ifconfig -l", controller)

    def test_api_view_and_configd_use_client_defaults(self):
        controller = (
            PLUGIN
            / "opnsense/mvc/app/controllers/OPNsense/TrustTunnelClient/Api/ServiceController.php"
        ).read_text(encoding="utf-8")
        self.assertNotIn("$role = 'server'", controller)
        self.assertGreaterEqual(controller.count("$role = 'client'"), 5)

        view = (
            PLUGIN
            / "opnsense/mvc/app/views/OPNsense/TrustTunnelClient/client.volt"
        ).read_text(encoding="utf-8")
        self.assertIn("data.trusttunnelclient.client.active_server", view)
        self.assertNotIn("data.trusttunnel.client.active_server", view)

        actions = (
            PLUGIN
            / "opnsense/service/conf/actions.d/actions_trusttunnelclient.conf"
        ).read_text(encoding="utf-8")
        reconfigure = actions.split("[client.reconfigure]", 1)[1]
        self.assertIn("&& echo OK", reconfigure)

    def test_client_grid_initializes_even_if_active_server_request_fails(self):
        view = (
            PLUGIN
            / "opnsense/mvc/app/views/OPNsense/TrustTunnelClient/client.volt"
        ).read_text(encoding="utf-8")
        grid_init = view.index('$("#grid-servers").UIBootgrid')
        active_request = view.index(
            "ajaxGet('/api/trusttunnelclient/client/get"
        )
        self.assertLess(grid_init, active_request)
        self.assertIn('$("#grid-servers").bootgrid(\'reload\')', view)
        self.assertNotIn("/api/trusttunnelclient/client/get/", view)

    def test_endpoint_rc_script_tracks_the_child_process(self):
        relative = "net/os-trusttunnel/src/etc/rc.d/trusttunnel_endpoint"
        script = (ROOT / relative).read_text(encoding="utf-8")
        self.assertIn("-p ${pidfile}", script)
        self.assertNotIn("-P ${pidfile}", script)

    def test_client_rc_script_supervises_and_restarts_the_child(self):
        relative = "net/os-trusttunnel-client/src/etc/rc.d/trusttunnel_client"
        script = (ROOT / relative).read_text(encoding="utf-8")
        self.assertIn("procname=/usr/sbin/daemon", script)
        self.assertIn(
            ': ${trusttunnel_client_binary:="/usr/local/sbin/trusttunnel_client"}',
            script,
        )
        self.assertIn("client_binary=${trusttunnel_client_binary}", script)
        self.assertIn("child_pidfile=/var/run/${name}.child.pid", script)
        self.assertIn("-P ${pidfile}", script)
        self.assertIn("-p ${child_pidfile}", script)
        self.assertIn(" -r -R 5", script)
        self.assertIn("-R 5", script)

    def test_client_status_requires_supervisor_and_live_child(self):
        relative = "net/os-trusttunnel-client/src/etc/rc.d/trusttunnel_client"
        script = (ROOT / relative).read_text(encoding="utf-8")
        self.assertIn("status_cmd=trusttunnel_client_status", script)
        self.assertIn('check_pidfile "$pidfile" "$procname"', script)
        self.assertIn(
            'pgrep -P "$supervisor_pid" -x "$client_name"', script
        )
        self.assertIn("without exactly one live client child", script)

    def test_client_boot_hooks_follow_enabled_model_state(self):
        start_hook = (
            PLUGIN / "etc/rc.syshook.d/start/55-trusttunnel-client"
        ).read_text(encoding="utf-8")
        stop_hook = (
            PLUGIN / "etc/rc.syshook.d/stop/45-trusttunnel-client"
        ).read_text(encoding="utf-8")
        self.assertIn(
            "pluginctl -g OPNsense.trusttunnelclient.client.enabled",
            start_hook,
        )
        self.assertIn(
            "configctl -dq trusttunnelclient client reconfigure",
            start_hook,
        )
        self.assertIn("configctl -dq trusttunnelclient client stop", stop_hook)
        self.assertNotIn("/conf/config.xml", start_hook + stop_hook)

    def test_status_actions_return_rc_output(self):
        for relative, section in (
            (
                "net/os-trusttunnel/src/opnsense/service/conf/actions.d/actions_trusttunnel.conf",
                "server.status",
            ),
            (
                "net/os-trusttunnel-client/src/opnsense/service/conf/actions.d/actions_trusttunnelclient.conf",
                "client.status",
            ),
        ):
            actions = (ROOT / relative).read_text(encoding="utf-8")
            body = actions.split(f"[{section}]", 1)[1].split("\n[", 1)[0]
            self.assertIn("type: script_output", body, relative)
            self.assertIn("errors:no", body, relative)

    def test_freebsd_tun_smoke_accepts_the_actual_bound_interface(self):
        tun = (ROOT / "tests/freebsd_client_tun_smoke.sh").read_text(
            encoding="utf-8"
        )
        self.assertIn("bound_if=${2:-vtnet0}", tun)
        self.assertIn('bound_if = "${bound_if}"', tun)

    def test_supervision_smoke_uses_a_deterministic_worker(self):
        supervision = (
            ROOT / "tests/freebsd_client_supervision_smoke.sh"
        ).read_text(encoding="utf-8")
        self.assertIn('cp /bin/sleep "$worker_bin"', supervision)
        self.assertIn('trusttunnel_client_binary="$worker_bin"', supervision)
        self.assertIn("trusttunnel_client_args=3600", supervision)


if __name__ == "__main__":
    unittest.main()
