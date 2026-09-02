import importlib.util
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

import tomllib


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "net/os-trusttunnel/src"
MODULE_PATH = PLUGIN / "opnsense/scripts/trusttunnel/render_server_config.py"


def load_module():
    spec = importlib.util.spec_from_file_location("render_server_config", MODULE_PATH)
    if spec is None or spec.loader is None:
        raise ImportError(f"cannot load {MODULE_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def build_server(allowed_sni="front.example.net,edge.example.org"):
    server = ET.Element("server")
    ET.SubElement(server, "hostname").text = "tt-e2e.example"
    ET.SubElement(server, "allowed_sni").text = allowed_sni
    return server


class RenderServerConfigTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.render = load_module()

    def test_hosts_render_allowed_sni(self):
        server = build_server()
        content = self.render.render_hosts_toml(server, hostname="tt-e2e.example")
        data = tomllib.loads(content)
        self.assertEqual(
            data["main_hosts"][0]["allowed_sni"],
            ["front.example.net", "edge.example.org"],
        )

    def test_subdomain_alias_is_rejected_as_sni_auth_syntax(self):
        server = build_server("front.tt-e2e.example")
        with self.assertRaisesRegex(ValueError, "SNI authentication"):
            self.render.render_hosts_toml(server, hostname="tt-e2e.example")

    def test_model_and_form_expose_allowed_sni(self):
        model = ET.parse(
            PLUGIN
            / "opnsense/mvc/app/models/OPNsense/TrustTunnel/TrustTunnel.xml"
        ).getroot()
        self.assertIsNotNone(model.find("./items/server/allowed_sni"))
        form = ET.parse(
            PLUGIN
            / "opnsense/mvc/app/controllers/OPNsense/TrustTunnel/forms/server.xml"
        ).getroot()
        ids = [field.findtext("id") for field in form.findall("field")]
        self.assertIn("trusttunnel.server.allowed_sni", ids)

    def test_vpn_omits_credentials_file_without_users(self):
        server = build_server()
        ET.SubElement(server, "listen_address").text = "127.0.0.1:443"
        content = self.render.render_vpn_toml(server, include_credentials=False)
        self.assertNotIn("credentials_file", tomllib.loads(content))

    def test_invalid_allowed_sni_does_not_partially_replace_outputs(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            temp = Path(temp_dir)
            config_path = temp / "config.xml"
            output_dir = temp / "server"
            output_dir.mkdir()

            root = ET.Element("root")
            opnsense = ET.SubElement(root, "OPNsense")
            trusttunnel = ET.SubElement(opnsense, "trusttunnel")
            server = ET.SubElement(trusttunnel, "server")
            ET.SubElement(server, "hostname").text = "tt-e2e.example"
            ET.SubElement(server, "listen_address").text = "127.0.0.1:443"
            ET.SubElement(server, "allowed_sni").text = "front.tt-e2e.example"
            ET.ElementTree(root).write(config_path, encoding="utf-8")

            outputs = [
                output_dir / "vpn.toml",
                output_dir / "hosts.toml",
                output_dir / "credentials.toml",
                output_dir / "rules.toml",
            ]
            for output in outputs:
                output.write_text("before\n", encoding="utf-8")

            original = (
                self.render.CONFIG_PATH,
                self.render.OUT_DIR,
                self.render.CERT_DIR,
            )
            self.render.CONFIG_PATH = config_path
            self.render.OUT_DIR = output_dir
            self.render.CERT_DIR = output_dir / "certs"
            try:
                self.assertEqual(self.render.main(), 1)
            finally:
                (
                    self.render.CONFIG_PATH,
                    self.render.OUT_DIR,
                    self.render.CERT_DIR,
                ) = original

            self.assertEqual(
                [output.read_text(encoding="utf-8") for output in outputs],
                ["before\n"] * 4,
            )


if __name__ == "__main__":
    unittest.main()
