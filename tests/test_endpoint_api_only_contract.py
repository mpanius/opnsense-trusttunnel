import unittest
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "net/os-trusttunnel"
CONTROLLER = (
    PLUGIN
    / "src/opnsense/mvc/app/controllers/OPNsense/TrustTunnel/Api/ServerController.php"
)
MODEL = (
    PLUGIN
    / "src/opnsense/mvc/app/models/OPNsense/TrustTunnel/TrustTunnel.xml"
)
VIEW = (
    PLUGIN
    / "src/opnsense/mvc/app/views/OPNsense/TrustTunnel/server.volt"
)
POST_DEINSTALL = PLUGIN / "+POST_DEINSTALL.post"
PACKAGE_LIFECYCLE_SMOKE = ROOT / "tests/smoke_endpoint_package_lifecycle.sh"


class EndpointApiOnlyContractTest(unittest.TestCase):
    def test_controller_does_not_bypass_supported_subsystem_apis(self):
        source = CONTROLLER.read_text(encoding="utf-8")
        for forbidden in (
            "Config::getInstance",
            "syncFirewallRule",
            "writeCertToTrustStore",
            "generateSelfSignedAction",
            "plugin_managed",
        ):
            self.assertNotIn(forbidden, source)

    def test_model_does_not_claim_ownership_of_firewall_rules(self):
        model = ET.parse(MODEL).getroot()
        self.assertIsNone(model.find("./items/server/firewall_rule_uuid"))

    def test_ui_does_not_offer_direct_trust_store_write(self):
        source = VIEW.read_text(encoding="utf-8")
        self.assertNotIn("generateSelfSigned", source)
        self.assertNotIn("btnGenSelfSigned", source)
        self.assertNotIn("DialogGenCert", source)

    def test_uninstall_does_not_mutate_persistent_config(self):
        source = POST_DEINSTALL.read_text(encoding="utf-8")
        self.assertNotIn("/conf/config.xml", source)
        self.assertNotIn("Config::getInstance", source)
        self.assertNotIn("plugin_managed", source)
        self.assertNotIn("configctl", source)

    def test_package_lifecycle_smoke_proves_config_immutability(self):
        source = PACKAGE_LIFECYCLE_SMOKE.read_text(encoding="utf-8")
        for required in (
            "sha256 -q /conf/config.xml",
            "pkg delete -y os-trusttunnel",
            "test \"$after_delete_sha\" = \"$before_sha\"",
            "test ! -e /usr/local/etc/trusttunnel/server",
            "test \"$after_reinstall_sha\" = \"$before_sha\"",
        ):
            self.assertIn(required, source)

    def test_endpoint_boot_hooks_follow_enabled_model_state(self):
        start_hook = (
            PLUGIN / "src/etc/rc.syshook.d/start/50-trusttunnel-endpoint"
        ).read_text(encoding="utf-8")
        stop_hook = (
            PLUGIN / "src/etc/rc.syshook.d/stop/50-trusttunnel-endpoint"
        ).read_text(encoding="utf-8")
        self.assertIn(
            "pluginctl -g OPNsense.trusttunnel.server.enabled",
            start_hook,
        )
        self.assertIn(
            "configctl -dq trusttunnel server reconfigure",
            start_hook,
        )
        self.assertIn("configctl -dq trusttunnel server stop", stop_hook)
        self.assertNotIn("/conf/config.xml", start_hook + stop_hook)


if __name__ == "__main__":
    unittest.main()
