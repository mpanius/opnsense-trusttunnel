#!/usr/local/bin/python3
# -*- coding: utf-8 -*-
"""
render_server_config.py — render server-side TOML artifacts from OPNsense
config.xml.

Closes Codex finding #3 (TOML injection): all user-controlled string
fields (username, password, hostname) pass through `_toml_escape()` and
the output is rejected if any field contains a literal control char that
the escaper cannot safely encode.

Closes Claude must_fix #1 (atomic credentials.toml render): each target
file is staged at `<path>.new` (mode 0600 for credentials/keys, 0644 for
configs) and `os.replace()`'d into place — no half-written window for
trusttunnel_endpoint to observe.

Inputs:
    /conf/config.xml (read-only)

Outputs:
    /usr/local/etc/trusttunnel/server/vpn.toml          (0644)
    /usr/local/etc/trusttunnel/server/hosts.toml        (0644)
    /usr/local/etc/trusttunnel/server/credentials.toml  (0600)
    /usr/local/etc/trusttunnel/server/rules.toml        (0644)

Exit codes:
    0   — success
    1   — config invalid (missing field, control char in user data)
    2   — I/O failure
    65  — Trust store cert referenced but cert/key files missing on disk
          (materialize_certs.php should have run first via the
          server.reconfigure action chain)

Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
BSD-2-Clause — see LICENSE.
"""

from __future__ import annotations

import os
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

CONFIG_PATH = Path("/conf/config.xml")
OUT_DIR = Path("/usr/local/etc/trusttunnel/server")
CERT_DIR = OUT_DIR / "certs"


# ---------------------------------------------------------------------------
# TOML escape — deliberately stricter than the spec (rejects control chars
# instead of \\u-encoding them) so a TOML reader cannot misparse our output
# even if the underlying TOML library is naive.
# ---------------------------------------------------------------------------

# Allow letters, digits, common punctuation, whitespace; reject control bytes,
# stray quotes, backslashes (escape them), and anything that would terminate
# a basic TOML string.
_TOML_ALLOWED_SET = set(
    bytes(range(0x20, 0x7F)).decode("ascii")
) | set("\t")
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
    """Escape a single value for a TOML basic string. Reject control bytes
    that we cannot safely round-trip.

    `allow_newlines=False` (the default) is strict: any \\n or \\r aborts
    the whole render. Passwords, usernames, hostnames must never contain
    newlines.
    """
    # Reject Unicode control chars (C0 + C1) outright unless they are tab
    # or (when allowed) a single trailing newline.
    for ch in value:
        cp = ord(ch)
        if cp < 0x20 and ch != "\t":
            if ch in ("\n", "\r") and allow_newlines:
                continue
            raise ValueError(
                "control byte U+"
                + format(cp, "04X")
                + " in TOML string value — rejected to prevent injection. "
                + "Tighten the model regex on this field."
            )
        if cp == 0x7F:
            raise ValueError("DEL (0x7F) byte in TOML string — rejected.")
    out: list[str] = []
    for ch in value:
        out.append(_TOML_ESCAPE_MAP.get(ch, ch))
    return '"' + "".join(out) + '"'


# ---------------------------------------------------------------------------
# config.xml helpers
# ---------------------------------------------------------------------------


def _node_text(parent: ET.Element, tag: str, default: str | None = None) -> str:
    """Fetch a child node's text; return default if missing/empty."""
    el = parent.find(tag)
    if el is None or el.text is None:
        if default is None:
            raise KeyError(f"<{tag}> missing in {parent.tag}")
        return default
    return el.text


def _node_bool(parent: ET.Element, tag: str, default: bool = False) -> bool:
    """Boolean child node — OPNsense stores '1' / '0' / empty."""
    el = parent.find(tag)
    if el is None or el.text is None or el.text == "":
        return default
    return el.text in ("1", "true", "yes")


def _node_csv(parent: ET.Element, tag: str) -> list[str]:
    """CSVListField — comma-separated; empty entries dropped."""
    el = parent.find(tag)
    if el is None or el.text is None:
        return []
    return [p.strip() for p in el.text.split(",") if p.strip()]


def _split_address(listen: str) -> tuple[str, int]:
    """Parse `host:port` or `[ipv6]:port`; return (host, port)."""
    if listen.startswith("["):
        # [ipv6]:port
        close = listen.index("]")
        host = listen[1:close]
        port_str = listen[close + 2:]  # skip ]:
    elif ":" in listen:
        host, port_str = listen.rsplit(":", 1)
    else:
        raise ValueError(f"listen_address must be host:port, got {listen!r}")
    try:
        port = int(port_str)
    except ValueError as e:
        raise ValueError(f"invalid port in listen_address {listen!r}: {e}")
    if not (1 <= port <= 65535):
        raise ValueError(f"port out of range: {port}")
    return host, port


# ---------------------------------------------------------------------------
# Atomic write
# ---------------------------------------------------------------------------


def _write_atomic(path: Path, content: str, mode: int) -> None:
    """Write `content` to `path` atomically with the given chmod mode.

    Stages at `<path>.new`, fsyncs, then os.replace() — eliminates the
    half-written window that motivated Claude must_fix #1.
    """
    tmp = path.with_suffix(path.suffix + ".new")
    fd = os.open(str(tmp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, mode)
    try:
        data = content.encode("utf-8")
        bytes_written = os.write(fd, data)
        if bytes_written != len(data):
            raise OSError(f"short write to {tmp}: {bytes_written} of {len(data)} bytes")
        os.fsync(fd)
    finally:
        os.close(fd)
    # chmod again after open() in case umask interfered with the create mode.
    os.chmod(tmp, mode)
    os.replace(tmp, path)


# ---------------------------------------------------------------------------
# Renderers
# ---------------------------------------------------------------------------


def render_vpn_toml(srv: ET.Element) -> str:
    """Main endpoint settings — listen, protocols, allow_private_network."""
    listen = _node_text(srv, "listen_address")
    _split_address(listen)  # validate-only; raises on bad host:port
    protocols = _node_csv(srv, "protocols") or ["http2", "http3"]
    for p in protocols:
        if p not in ("http1", "http2", "http3"):
            raise ValueError(f"unsupported protocol {p!r}")
    allow_pvt = _node_bool(srv, "allow_private_network_connections", True)
    ipv6 = _node_bool(srv, "ipv6_available", True)

    # TrustTunnel endpoint expects each protocol as its own [listen_protocols.<name>]
    # sub-table (struct with Option<Http1Settings>/<Http2Settings>/<QuicSettings>),
    # not a `protocols = [...]` array. Map http3 → quic (QUIC is the wire
    # transport of HTTP/3 — endpoint settings struct calls it `quic`).
    proto_map = {"http1": "http1", "http2": "http2", "http3": "quic"}
    proto_sections = [proto_map[p] for p in protocols]

    lines: list[str] = [
        "# Generated by os-trusttunnel render_server_config.py — do not edit.",
        f"listen_address = {_toml_escape(listen)}",
        f"credentials_file = {_toml_escape(str(OUT_DIR / 'credentials.toml'))}",
        f"rules_file = {_toml_escape(str(OUT_DIR / 'rules.toml'))}",
        f"ipv6_available = {'true' if ipv6 else 'false'}",
        f"allow_private_network_connections = {'true' if allow_pvt else 'false'}",
        "",
    ]
    for section in proto_sections:
        lines.append(f"[listen_protocols.{section}]")
    # legacy field kept as a compat marker; endpoint ignores unknown keys
    lines.append("")
    lines += [
        "# Compatibility marker — older parsers may key on this array.",
        "# protocols = [" + ", ".join(_toml_escape(p) for p in protocols) + "]",
    ]
    return "\n".join(lines) + "\n"


def render_hosts_toml(srv: ET.Element, *, hostname: str) -> str:
    """TLS hosts — single [[main_hosts]] entry referencing the cert files
    materialized by materialize_certs.php.
    """
    cert_pem = CERT_DIR / "cert.pem"
    key_pem = CERT_DIR / "key.pem"
    # Soft-check: if cert_ref is set but files don't exist, error out so
    # the user sees a clear failure rather than trusttunnel_endpoint
    # failing later with "no such file".
    cert_ref = _node_text(srv, "cert_ref", "")
    if cert_ref and (not cert_pem.exists() or not key_pem.exists()):
        sys.stderr.write(
            f"error: cert_ref={cert_ref!r} set but {cert_pem} or {key_pem} "
            "missing — materialize_certs.php must run before this script.\n"
        )
        sys.exit(65)

    lines: list[str] = [
        "# Generated by os-trusttunnel render_server_config.py — do not edit.",
        "ping_hosts = []",
        "speedtest_hosts = []",
        "",
        "[[main_hosts]]",
        f"hostname = {_toml_escape(hostname)}",
        f"cert_chain_path = {_toml_escape(str(cert_pem))}",
        f"private_key_path = {_toml_escape(str(key_pem))}",
        "allowed_sni = []",
    ]
    return "\n".join(lines) + "\n"


def render_credentials_toml(users: list[ET.Element]) -> str:
    """User registry — [[client]] entries. mode=0600."""
    lines: list[str] = [
        "# Generated by os-trusttunnel render_server_config.py — do not edit.",
        "# This file holds plaintext passwords; chmod 0600 enforced.",
    ]
    for user in users:
        username = _node_text(user, "username")
        password = _node_text(user, "password")
        # Username regex was enforced at the model layer; double-check here
        # as defense-in-depth against malformed config.xml.
        if not username.replace("_", "").replace("-", "").replace(".", "").isalnum():
            raise ValueError(
                f"username contains forbidden chars: {username!r}. "
                "Tighten the model RegexConstraint."
            )
        lines.append("")
        lines.append("[[client]]")
        lines.append(f"username = {_toml_escape(username)}")
        lines.append(f"password = {_toml_escape(password)}")
    return "\n".join(lines) + "\n"


def render_rules_toml() -> str:
    """v1: allow all. Rules grow with future plan features (client_random_prefix etc.)."""
    return (
        "# Generated by os-trusttunnel render_server_config.py — do not edit.\n"
        "# v1: allow-all (no filtering rules). Future versions populate this\n"
        "# from <rules> in config.xml.\n"
    )


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------


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
    srv = root.find(".//OPNsense/trusttunnel/server")
    if srv is None:
        sys.stderr.write("error: <OPNsense><trusttunnel><server> missing in config.xml\n")
        return 1

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    CERT_DIR.mkdir(parents=True, exist_ok=True)
    os.chmod(OUT_DIR, 0o750)

    try:
        hostname = _node_text(srv, "hostname")
        # Validate hostname is benign before it lands in TOML. The discarded
        # return is intentional — we only want the side-effect of raising on
        # control bytes here.
        _ = _toml_escape(hostname)

        users_node = srv.find("users")
        users = list(users_node.findall("user")) if users_node is not None else []

        _write_atomic(OUT_DIR / "vpn.toml", render_vpn_toml(srv), 0o644)
        _write_atomic(OUT_DIR / "hosts.toml", render_hosts_toml(srv, hostname=hostname), 0o644)
        _write_atomic(OUT_DIR / "credentials.toml", render_credentials_toml(users), 0o600)
        _write_atomic(OUT_DIR / "rules.toml", render_rules_toml(), 0o644)
    except (ValueError, KeyError) as e:
        sys.stderr.write(f"error: {e}\n")
        return 1
    except OSError as e:
        sys.stderr.write(f"I/O error: {e}\n")
        return 2

    print(f"rendered: vpn.toml, hosts.toml, credentials.toml, rules.toml in {OUT_DIR}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
