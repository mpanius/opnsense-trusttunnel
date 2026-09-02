#!/usr/local/bin/python3
# -*- coding: utf-8 -*-
"""
render_client_config.py — render trusttunnel_client.toml from the
currently active server row in config.xml.

Uses the same TOML-injection and atomic-write safeguards as the endpoint
renderer, implemented locally so the client plugin remains independent.

Inputs:
    /conf/config.xml (read-only) — <OPNsense><trusttunnelclient><client>...

Outputs:
    /usr/local/etc/trusttunnel/client/trusttunnel_client.toml (0600 — has
    plaintext password for the active server)

Exit codes:
    0   — success
    1   — config invalid (no <client>, no active server, validation fail)
    2   — I/O failure
    65  — active server row references a UUID not in <servers>

Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
BSD-2-Clause — see LICENSE.
"""

from __future__ import annotations

import os
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

CONFIG_PATH = Path("/conf/config.xml")
OUT_DIR     = Path("/usr/local/etc/trusttunnel/client")
OUT_FILE    = OUT_DIR / "trusttunnel_client.toml"


_TOML_ESCAPE_MAP = {
    "\\": "\\\\",
    '"': '\\"',
    "\b": "\\b",
    "\t": "\\t",
    "\n": "\\n",
    "\f": "\\f",
    "\r": "\\r",
}


def _toml_escape(value: str, *, allow_newlines: bool = False) -> str:
    """Render one TOML basic string and reject unsafe control bytes."""
    for char in value:
        codepoint = ord(char)
        if codepoint < 0x20 and char != "\t":
            if char in ("\n", "\r") and allow_newlines:
                continue
            raise ValueError(f"control byte U+{codepoint:04X} in TOML value")
        if codepoint == 0x7F:
            raise ValueError("DEL byte in TOML value")
    return '"' + "".join(_TOML_ESCAPE_MAP.get(char, char) for char in value) + '"'


def _write_atomic(path: Path, content: str, mode: int) -> None:
    tmp = path.with_suffix(path.suffix + ".new")
    fd = os.open(str(tmp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, mode)
    try:
        data = content.encode("utf-8")
        written = os.write(fd, data)
        if written != len(data):
            raise OSError(f"short write to {tmp}: {written} of {len(data)} bytes")
        os.fsync(fd)
    finally:
        os.close(fd)
    os.chmod(tmp, mode)
    os.replace(tmp, path)


def _text(parent: ET.Element, tag: str, default: str = "") -> str:
    el = parent.find(tag)
    if el is None or el.text is None:
        return default
    return el.text


def _bool(parent: ET.Element, tag: str, default: bool = False) -> bool:
    el = parent.find(tag)
    if el is None or el.text is None or el.text == "":
        return default
    return el.text in ("1", "true", "yes")


def _csv(parent: ET.Element, tag: str) -> list[str]:
    el = parent.find(tag)
    if el is None or el.text is None:
        return []
    return [p.strip() for p in el.text.split(",") if p.strip()]


def find_active_server(client: ET.Element) -> ET.Element:
    active = _text(client, "active_server")
    if active == "":
        sys.exit("error: <active_server> empty — pick a server in the UI before Apply")
    servers_node = client.find("servers")
    if servers_node is None:
        sys.exit("error: <servers> missing")
    for srv in servers_node.findall("server"):
        # OPNsense ArrayField rows are addressed by their auto-generated
        # @uuid attribute on the wrapping element. ET stores it as
        # `.attrib['uuid']`.
        if srv.attrib.get("uuid") == active:
            return srv
    # Fall back: some OPNsense versions store uuid as a child element.
    for srv in servers_node.findall("server"):
        if _text(srv, "uuid") == active:
            return srv
    sys.stderr.write(f"error: active_server={active!r} not found in <servers>\n")
    sys.exit(65)


def render_client_toml(client: ET.Element, srv: ET.Element) -> str:
    mode = _text(client, "mode", "general")
    tun_iface = _text(client, "tun_interface")
    included_routes = _csv(client, "allowed_destinations")
    excluded_routes = _csv(client, "excluded_destinations")
    use_existing = _bool(client, "use_existing", False)
    change_system_dns = _bool(client, "change_system_dns", False)
    bound_if = _text(client, "bound_if")
    try:
        mtu_size = int(_text(client, "mtu_size", "1350"))
    except ValueError as error:
        raise ValueError("mtu_size must be an integer") from error

    hostname = _text(srv, "hostname")
    addresses = _csv(srv, "addresses")
    if not addresses:
        # Compose a default address from <hostname>:443.
        addresses = [f"{hostname}:443"]
    custom_sni = _text(srv, "custom_sni")
    username = _text(srv, "username")
    password = _text(srv, "password")
    skip_verify = _bool(srv, "skip_verification", False)
    upstream = _text(srv, "upstream_protocol", "http2")
    anti_dpi = _bool(srv, "anti_dpi", False)
    cli_rnd_prefix = _text(srv, "client_random_prefix")
    dns_upstreams = _csv(srv, "dns_upstreams")
    cert_pem = _text(srv, "certificate_pem")

    if mode not in ("general", "selective"):
        sys.exit(f"error: mode must be general or selective, got {mode!r}")
    if upstream not in ("http2", "http3"):
        sys.exit(f"error: upstream_protocol must be http2 or http3, got {upstream!r}")
    if username == "" or password == "":
        sys.exit("error: active server missing username/password")
    if hostname == "":
        sys.exit("error: active server missing hostname")
    if not 576 <= mtu_size <= 9000:
        sys.exit("error: mtu_size must be between 576 and 9000")
    if tun_iface and re.fullmatch(r"tun[0-9]+", tun_iface) is None:
        sys.exit("error: FreeBSD device_name must be empty or tun<N>")
    if use_existing and not tun_iface:
        sys.exit("error: use_existing requires a non-empty tun_interface")
    if change_system_dns:
        sys.exit("error: FreeBSD/OPNsense must manage system DNS; disable change_system_dns")
    if not bound_if:
        sys.exit("error: bound_if is required on FreeBSD/OPNsense")

    # Validate username against the same regex the server-side enforces.
    if not all(c.isalnum() or c in "._-" for c in username):
        sys.exit("error: active server username contains forbidden chars")

    lines: list[str] = [
        "# Generated by os-trusttunnel render_client_config.py — do not edit.",
        'loglevel = "info"',
        f"vpn_mode = {_toml_escape(mode)}",
        "killswitch_enabled = false",
        "exclusions = ["
        + ", ".join(_toml_escape(item) for item in (included_routes if mode == "selective" else []))
        + "]",
    ]

    lines.append("")
    lines.append("[endpoint]")
    lines.append(f"hostname = {_toml_escape(hostname)}")
    lines.append(f"custom_sni = {_toml_escape(custom_sni)}")
    lines.append("addresses = [" + ", ".join(_toml_escape(a) for a in addresses) + "]")
    lines.append("has_ipv6 = false")
    lines.append(f"username = {_toml_escape(username)}")
    lines.append(f"password = {_toml_escape(password)}")
    lines.append(f"skip_verification = {'true' if skip_verify else 'false'}")
    lines.append(f"certificate = {_toml_escape(cert_pem, allow_newlines=True)}")
    lines.append(f"upstream_protocol = {_toml_escape(upstream)}")
    lines.append(f"anti_dpi = {'true' if anti_dpi else 'false'}")
    if cli_rnd_prefix:
        lines.append(f"client_random = {_toml_escape(cli_rnd_prefix)}")
    lines.append("dns_upstreams = [" + ", ".join(_toml_escape(d) for d in dns_upstreams) + "]")

    lines.append("")
    lines.append("[listener.tun]")
    lines.append(f"bound_if = {_toml_escape(bound_if)}")
    lines.append("included_routes = [" + ", ".join(_toml_escape(route) for route in included_routes) + "]")
    lines.append("excluded_routes = [" + ", ".join(_toml_escape(route) for route in excluded_routes) + "]")
    lines.append(f"mtu_size = {mtu_size}")
    lines.append("change_system_dns = false")
    lines.append(f"device_name = {_toml_escape(tun_iface)}")
    lines.append(f"use_existing = {'true' if use_existing else 'false'}")

    return "\n".join(lines) + "\n"


def main() -> int:
    if not CONFIG_PATH.is_file():
        sys.stderr.write(f"error: {CONFIG_PATH} not found\n")
        return 2
    try:
        tree = ET.parse(CONFIG_PATH)
    except ET.ParseError as e:
        sys.stderr.write(f"error: cannot parse {CONFIG_PATH}: {e}\n")
        return 2
    root = tree.getroot()
    client = root.find(".//OPNsense/trusttunnelclient/client")
    if client is None:
        sys.stderr.write("error: <OPNsense><trusttunnelclient><client> missing\n")
        return 1

    try:
        srv = find_active_server(client)
        OUT_DIR.mkdir(parents=True, exist_ok=True)
        os.chmod(OUT_DIR, 0o750)
        _write_atomic(OUT_FILE, render_client_toml(client, srv), 0o600)
    except (ValueError, KeyError) as e:
        sys.stderr.write(f"error: {e}\n")
        return 1
    except OSError as e:
        sys.stderr.write(f"I/O error: {e}\n")
        return 2

    print(f"rendered {OUT_FILE}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
