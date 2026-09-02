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

    def test_rc_scripts_track_the_child_process(self):
        for relative in (
            "net/os-trusttunnel/src/etc/rc.d/trusttunnel_endpoint",
            "net/os-trusttunnel-client/src/etc/rc.d/trusttunnel_client",
        ):
            script = (ROOT / relative).read_text(encoding="utf-8")
            self.assertIn("-p ${pidfile}", script, relative)
            self.assertNotIn("-P ${pidfile}", script, relative)


if __name__ == "__main__":
    unittest.main()
