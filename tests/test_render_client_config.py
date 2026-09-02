import importlib.util
import os
import stat
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path
from unittest import mock

import tomllib


MODULE_PATH = (
    Path(__file__).resolve().parents[1]
    / "net/os-trusttunnel-client/src/opnsense/scripts/trusttunnelclient"
    / "render_client_config.py"
)


def load_module():
    spec = importlib.util.spec_from_file_location("render_client_config", MODULE_PATH)
    if spec is None or spec.loader is None:
        raise ImportError(f"cannot load {MODULE_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def build_config(*, mode="selective", active_server="server-1"):
    root = ET.Element("opnsense")
    opnsense = ET.SubElement(root, "OPNsense")
    client = ET.SubElement(ET.SubElement(opnsense, "trusttunnelclient"), "client")
    values = {
        "active_server": active_server,
        "mode": mode,
        "tun_interface": "tun0",
        "allowed_destinations": "198.51.100.0/24,203.0.113.0/24",
        "excluded_destinations": "192.0.2.0/24",
        "use_existing": "0",
        "mtu_size": "1350",
        "change_system_dns": "0",
        "bound_if": "vtnet1",
    }
    for tag, value in values.items():
        ET.SubElement(client, tag).text = value

    servers = ET.SubElement(client, "servers")
    server = ET.SubElement(servers, "server", {"uuid": "server-1"})
    server_values = {
        "hostname": "origin.example.com",
        "addresses": "192.0.2.10:443",
        "custom_sni": "edge.example.net",
        "username": "test_user",
        "password": "secret",
        "upstream_protocol": "http2",
        "client_random_prefix": "aabb/ffff",
        "certificate_pem": (
            "-----BEGIN CERTIFICATE-----\nline\n-----END CERTIFICATE-----\n"
        ),
        "dns_upstreams": "1.1.1.1:53",
    }
    for tag, value in server_values.items():
        ET.SubElement(server, tag).text = value
    return root, client, server


class RenderClientConfigTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.render = load_module()

    def test_selective_config_matches_upstream_contract(self):
        _, client, server = build_config()
        data = tomllib.loads(self.render.render_client_toml(client, server))

        self.assertEqual(data["vpn_mode"], "selective")
        self.assertEqual(
            data["exclusions"], ["198.51.100.0/24", "203.0.113.0/24"]
        )
        self.assertNotIn("server", data)
        endpoint = data["endpoint"]
        self.assertEqual(endpoint["hostname"], "origin.example.com")
        self.assertEqual(endpoint["custom_sni"], "edge.example.net")
        self.assertEqual(endpoint["client_random"], "aabb/ffff")
        self.assertFalse(endpoint["has_ipv6"])
        self.assertEqual(
            endpoint["certificate"],
            "-----BEGIN CERTIFICATE-----\nline\n-----END CERTIFICATE-----\n",
        )

        tun = data["listener"]["tun"]
        self.assertEqual(tun["device_name"], "tun0")
        self.assertFalse(tun["use_existing"])
        self.assertEqual(
            tun["included_routes"], ["198.51.100.0/24", "203.0.113.0/24"]
        )
        self.assertEqual(tun["excluded_routes"], ["192.0.2.0/24"])
        self.assertEqual(tun["mtu_size"], 1350)
        self.assertFalse(tun["change_system_dns"])
        self.assertEqual(tun["bound_if"], "vtnet1")

    def test_general_mode_does_not_treat_managed_routes_as_direct(self):
        _, client, server = build_config(mode="general")
        data = tomllib.loads(self.render.render_client_toml(client, server))
        self.assertEqual(data["vpn_mode"], "general")
        self.assertEqual(data["exclusions"], [])
        self.assertEqual(
            data["listener"]["tun"]["included_routes"],
            ["198.51.100.0/24", "203.0.113.0/24"],
        )

    def test_main_reads_real_model_mount_and_writes_mode_0600(self):
        root, _, _ = build_config()
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            config_path = tmp_path / "config.xml"
            out_dir = tmp_path / "output"
            out_file = out_dir / "trusttunnel_client.toml"
            ET.ElementTree(root).write(config_path, encoding="unicode")
            with (
                mock.patch.object(self.render, "CONFIG_PATH", config_path),
                mock.patch.object(self.render, "OUT_DIR", out_dir),
                mock.patch.object(self.render, "OUT_FILE", out_file),
            ):
                self.assertEqual(self.render.main(), 0)
            self.assertEqual(stat.S_IMODE(os.stat(out_file).st_mode), 0o600)
            tomllib.loads(out_file.read_text(encoding="utf-8"))

    def test_stale_active_server_exits_65(self):
        _, client, _ = build_config(active_server="missing")
        with self.assertRaises(SystemExit) as raised:
            self.render.find_active_server(client)
        self.assertEqual(raised.exception.code, 65)

    def test_empty_bound_interface_is_rejected_on_freebsd(self):
        _, client, server = build_config()
        client.find("bound_if").text = ""
        with self.assertRaisesRegex(SystemExit, "bound_if"):
            self.render.render_client_toml(client, server)


if __name__ == "__main__":
    unittest.main()
