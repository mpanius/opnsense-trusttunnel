#!/usr/local/bin/python3
# -*- coding: utf-8 -*-
"""
deeplink_export.py — generate a tt://?<base64url(TLV)> deep-link for a
given server user, plus a QR-encoded PNG (base64-inline), as JSON.

Reuses the TLV/varint encoding logic from upstream TrustTunnel
scripts/config_to_deeplink.py. Reads from /conf/config.xml directly
(config.xml = single source of truth, per Codex resolution).

CLI:
    deeplink_export.py --user=<username> [--name=<display_name>]
                       [--no-qr]

Notes on argparse hardening (Claude should_fix #7):
    --user must use the `--user=NAME` form (we pass the value as one
    argv element via configd's parameter substitution). The Python
    argparse parser still accepts `--user NAME` but the configd action
    binds it as a single =-joined argument.

Output (stdout, JSON):
    {"uri": "tt://?...", "qr_png_base64": "iVBOR..."}

Exit codes:
    0  ok
    1  user/config error
    2  I/O failure (qrencode missing, etc.)

Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
BSD-2-Clause — see LICENSE. The TLV/varint helpers below are derived
from TrustTunnel/scripts/config_to_deeplink.py (Apache-2.0); see that
file in the upstream TrustTunnel repo for the canonical implementation.
"""

from __future__ import annotations

import argparse
import base64
import json
import re
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

CONFIG_PATH = Path("/conf/config.xml")
CERT_DIR    = Path("/usr/local/etc/trusttunnel/server/certs")
MAX_URI_BYTES = 65536  # mirrors the import-side cap

# ---------------------------------------------------------------------------
# TLV / varint helpers — ported from upstream config_to_deeplink.py
# ---------------------------------------------------------------------------


def encode_varint(value: int) -> bytes:
    if value < 0:
        raise ValueError("varint value must be non-negative")
    if value <= 0x3F:
        return value.to_bytes(1, "big")
    if value <= 0x3FFF:
        return (value | 0x4000).to_bytes(2, "big")
    if value <= 0x3FFFFFFF:
        return (value | 0x80000000).to_bytes(4, "big")
    if value <= 0x3FFFFFFFFFFFFFFF:
        return (value | 0xC000000000000000).to_bytes(8, "big")
    raise ValueError(f"varint value too large: {value}")


def tlv(tag: int, value: bytes) -> bytes:
    return encode_varint(tag) + encode_varint(len(value)) + value


TAG_VERSION              = 0x00
TAG_HOSTNAME             = 0x01
TAG_ADDRESS              = 0x02
TAG_CUSTOM_SNI           = 0x03
TAG_HAS_IPV6             = 0x04
TAG_USERNAME             = 0x05
TAG_PASSWORD             = 0x06
TAG_SKIP_VERIFICATION    = 0x07
TAG_CERTIFICATE          = 0x08
TAG_UPSTREAM_PROTOCOL    = 0x09
TAG_ANTI_DPI             = 0x0A
TAG_CLIENT_RANDOM_PREFIX = 0x0B
TAG_NAME                 = 0x0C
TAG_DNS_UPSTREAMS        = 0x0D

CURRENT_VERSION = 1
PROTOCOL_MAP = {"http2": 0x01, "http3": 0x02}

# ---------------------------------------------------------------------------
# PEM → DER (for embedding self-signed cert in the URI)
# ---------------------------------------------------------------------------

_PEM_RE = re.compile(
    r"-----BEGIN [A-Z0-9 ]+-----\s*\n"
    r"([\sA-Za-z0-9+/=]+?)"
    r"\n-----END [A-Z0-9 ]+-----",
)


def pem_to_der(pem: str) -> bytes:
    blocks = _PEM_RE.findall(pem)
    if not blocks:
        raise ValueError("no PEM blocks found in certificate")
    der = bytearray()
    for b64 in blocks:
        der += base64.b64decode(b64)
    return bytes(der)

# ---------------------------------------------------------------------------
# config.xml helpers
# ---------------------------------------------------------------------------


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


def find_user(srv: ET.Element, username: str) -> ET.Element | None:
    users_node = srv.find("users")
    if users_node is None:
        return None
    for u in users_node.findall("user"):
        if _text(u, "username") == username:
            return u
    return None


def find_trust_cert(root: ET.Element, refid: str) -> ET.Element | None:
    if refid == "":
        return None
    for cert in root.findall("./cert"):
        if _text(cert, "refid") == refid:
            return cert
    return None


def is_self_signed(cert_pem: str) -> bool:
    """Heuristic: a single CERTIFICATE block where Issuer == Subject. We
    don't do full X.509 parsing here — instead, if there is only one PEM
    block in the chain (no intermediates), we treat it as self-signed.
    That matches the deeplink spec's rule ("trusted CA chains omitted").
    """
    blocks = _PEM_RE.findall(cert_pem)
    return len(blocks) == 1


# ---------------------------------------------------------------------------
# Main encoder
# ---------------------------------------------------------------------------


def build_deeplink(username: str, display_name: str | None = None) -> str:
    if not CONFIG_PATH.is_file():
        sys.exit(f"error: {CONFIG_PATH} not found")
    try:
        tree = ET.parse(CONFIG_PATH)
    except ET.ParseError as e:
        sys.exit(f"error: cannot parse {CONFIG_PATH}: {e}")
    root = tree.getroot()

    srv = root.find(".//OPNsense/trusttunnel/server")
    if srv is None:
        sys.exit("error: <OPNsense><trusttunnel><server> missing in config.xml")

    user = find_user(srv, username)
    if user is None:
        sys.exit(f"error: user '{username}' not found in <users>")

    hostname = _text(srv, "hostname")
    if not hostname:
        sys.exit("error: <hostname> empty")
    listen = _text(srv, "listen_address", "0.0.0.0:443")
    # Extract port for the public address. host comes from <hostname>.
    if ":" in listen:
        port = listen.rsplit(":", 1)[1]
    else:
        port = "443"
    address = f"{hostname}:{port}"

    password = _text(user, "password")
    ipv6     = _bool(srv, "ipv6_available", True)
    cert_ref = _text(srv, "cert_ref", "")

    # ---- TLV payload ----
    buf = bytearray()
    buf += tlv(TAG_VERSION, encode_varint(CURRENT_VERSION))
    buf += tlv(TAG_HOSTNAME, hostname.encode())
    buf += tlv(TAG_ADDRESS, address.encode())
    buf += tlv(TAG_USERNAME, username.encode())
    buf += tlv(TAG_PASSWORD, password.encode())
    if not ipv6:
        buf += tlv(TAG_HAS_IPV6, b"\x00")
    if display_name:
        buf += tlv(TAG_NAME, display_name.encode())

    # Embed cert IFF it's self-signed (per DEEP_LINK.md spec — trusted CA
    # chains are omitted because system roots can verify them).
    if cert_ref:
        cert_entry = find_trust_cert(root, cert_ref)
        if cert_entry is None:
            sys.exit(f"error: cert refid={cert_ref} not in Trust store")
        crt_b64 = _text(cert_entry, "crt")
        if not crt_b64:
            sys.exit(f"error: cert refid={cert_ref} has empty <crt>")
        try:
            cert_pem = base64.b64decode(crt_b64).decode()
        except Exception as e:
            sys.exit(f"error: cannot base64-decode cert refid={cert_ref}: {e}")
        if is_self_signed(cert_pem):
            der = pem_to_der(cert_pem)
            buf += tlv(TAG_CERTIFICATE, der)

    encoded = base64.urlsafe_b64encode(bytes(buf)).rstrip(b"=").decode("ascii")
    uri = "tt://?" + encoded
    if len(uri) > MAX_URI_BYTES:
        sys.exit(f"error: generated URI is {len(uri)} bytes; exceeds {MAX_URI_BYTES} cap")
    return uri


def render_qr_png(uri: str) -> bytes:
    """Invoke qrencode to render the URI as a PNG."""
    try:
        proc = subprocess.run(
            ["qrencode", "-t", "PNG", "-o", "-", uri],
            capture_output=True,
            check=True,
            timeout=10,
        )
    except FileNotFoundError:
        sys.exit("error: qrencode not installed (FreeBSD pkg: graphics/qrencode)")
    except subprocess.CalledProcessError as e:
        sys.exit(f"error: qrencode failed (rc={e.returncode}): {e.stderr.decode('utf-8', 'replace')}")
    except subprocess.TimeoutExpired:
        sys.exit("error: qrencode timed out")
    return proc.stdout


def main() -> int:
    p = argparse.ArgumentParser(
        prog="deeplink_export.py",
        description="Generate a tt://? deeplink for a server user, with QR.",
        # Disable abbrev/prefix matching to block "--cert-file" style injection
        # via a username that starts with `--`. Combined with the model-layer
        # username regex this is defense in depth.
        allow_abbrev=False,
    )
    p.add_argument("--user", required=True, help="username (must exist in <users>)")
    p.add_argument("--name", default=None, help="display name for the client UI (optional)")
    p.add_argument("--no-qr", action="store_true", help="omit qr_png_base64 from output")
    args = p.parse_args()

    if args.user.startswith("-"):
        sys.exit("error: --user value must not start with '-'")

    uri = build_deeplink(args.user, args.name)
    out: dict[str, str] = {"uri": uri}
    if not args.no_qr:
        png = render_qr_png(uri)
        out["qr_png_base64"] = base64.b64encode(png).decode("ascii")

    json.dump(out, sys.stdout, separators=(",", ":"))
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
