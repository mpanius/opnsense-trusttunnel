#!/usr/local/bin/python3
# -*- coding: utf-8 -*-
"""
deeplink_parse.py — parse a tt://?<base64url(TLV)> URI from stdin and
emit a JSON description of the embedded fields plus the SHA-256
fingerprint of any embedded certificate.

Defends against DoS (Claude must_fix #2 + TS-009):
- Reads at most 65_536 bytes from stdin. Anything longer => exit 1.
- Rejects any TLV `length` field > MAX_FIELD_BYTES (64 KB) before
  slicing.
- Rejects embedded certificate over MAX_CERT_BYTES (16 KB).
- No external subprocess invocation; no qrencode here.

URI is read from STDIN (NOT argv) so it never appears in the proc
table. DeeplinkController invokes us via proc_open with a stdin pipe
and a 10 s stream_set_timeout.

Output (stdout, JSON):
    {
      "version": 1,
      "hostname": "...",
      "addresses": ["host:port", ...],
      "username": "...",
      "password": "...",
      "name": "..." (optional),
      "custom_sni": "..." (optional),
      "has_ipv6": true|false,
      "skip_verification": true|false,
      "anti_dpi": true|false,
      "upstream_protocol": "http2"|"http3",
      "client_random_prefix": "..." (optional),
      "dns_upstreams": [...] (optional),
      "certificate_pem": "..." (optional),
      "fingerprint_sha256": "AB:CD:..." (optional; only when cert present)
    }

Exit codes:
    0  ok
    1  parser error or oversized input

Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
BSD-2-Clause — see LICENSE. TLV/varint decoder is derived from
TrustTunnel/scripts/deeplink_to_config.py (Apache-2.0).
"""

from __future__ import annotations

import base64
import hashlib
import json
import sys
from typing import Any

MAX_URI_BYTES   = 65_536
MAX_FIELD_BYTES = 65_536
MAX_CERT_BYTES  = 16_384

# ---------------------------------------------------------------------------
# Varint decoder — bounded, no LEB128 loop, RFC 9000 §16
# ---------------------------------------------------------------------------


def decode_varint(data: bytes, offset: int) -> tuple[int, int]:
    if offset >= len(data):
        raise ValueError("unexpected end of data while reading varint")
    first = data[offset]
    prefix = first >> 6
    if prefix == 0:
        return first & 0x3F, offset + 1
    if prefix == 1:
        if offset + 2 > len(data):
            raise ValueError("truncated 2-byte varint")
        return int.from_bytes(data[offset:offset + 2], "big") & 0x3FFF, offset + 2
    if prefix == 2:
        if offset + 4 > len(data):
            raise ValueError("truncated 4-byte varint")
        return int.from_bytes(data[offset:offset + 4], "big") & 0x3FFFFFFF, offset + 4
    # prefix == 3
    if offset + 8 > len(data):
        raise ValueError("truncated 8-byte varint")
    return int.from_bytes(data[offset:offset + 8], "big") & 0x3FFFFFFFFFFFFFFF, offset + 8


# ---------------------------------------------------------------------------
# Field tags (mirrors DEEP_LINK.md and upstream scripts/deeplink_to_config.py)
# ---------------------------------------------------------------------------

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

PROTOCOL_RMAP = {0x01: "http2", 0x02: "http3"}


# ---------------------------------------------------------------------------
# TLV parser (with bounded length)
# ---------------------------------------------------------------------------


def parse_tlv(data: bytes) -> list[tuple[int, bytes]]:
    entries: list[tuple[int, bytes]] = []
    offset = 0
    while offset < len(data):
        tag, offset = decode_varint(data, offset)
        length, offset = decode_varint(data, offset)
        if length > MAX_FIELD_BYTES:
            raise ValueError(
                f"TLV length out of bounds: tag=0x{tag:02X} length={length} "
                f"(cap={MAX_FIELD_BYTES})"
            )
        if offset + length > len(data):
            raise ValueError(
                f"TLV value truncated: tag=0x{tag:02X}, "
                f"expected {length} bytes, got {len(data) - offset}"
            )
        entries.append((tag, bytes(data[offset:offset + length])))
        offset += length
    return entries


# ---------------------------------------------------------------------------
# DER → PEM + SHA-256 fingerprint
# ---------------------------------------------------------------------------


def _read_asn1_length(data: bytes, offset: int) -> tuple[int, int]:
    if offset >= len(data):
        raise ValueError("unexpected end of data in ASN.1 length")
    first = data[offset]
    if first < 0x80:
        return first, offset + 1
    num_bytes = first & 0x7F
    if num_bytes == 0 or offset + 1 + num_bytes > len(data):
        raise ValueError("invalid ASN.1 length encoding")
    length = int.from_bytes(data[offset + 1:offset + 1 + num_bytes], "big")
    return length, offset + 1 + num_bytes


def split_der_certs(data: bytes) -> list[bytes]:
    certs: list[bytes] = []
    offset = 0
    while offset < len(data):
        if data[offset] != 0x30:
            raise ValueError(
                f"expected ASN.1 SEQUENCE (0x30) at offset {offset}, got 0x{data[offset]:02X}"
            )
        body_len, hdr_end = _read_asn1_length(data, offset + 1)
        cert_end = hdr_end + body_len
        if cert_end > len(data):
            raise ValueError("truncated DER certificate")
        certs.append(bytes(data[offset:cert_end]))
        offset = cert_end
    return certs


def der_to_pem(data: bytes) -> str:
    pem_blocks: list[str] = []
    for der in split_der_certs(data):
        b64 = base64.b64encode(der).decode("ascii")
        lines = [b64[i:i + 64] for i in range(0, len(b64), 64)]
        pem_blocks.append(
            "-----BEGIN CERTIFICATE-----\n"
            + "\n".join(lines)
            + "\n-----END CERTIFICATE-----"
        )
    return "\n".join(pem_blocks) + "\n"


def fingerprint_sha256(der: bytes) -> str:
    digest = hashlib.sha256(der).hexdigest().upper()
    return ":".join(digest[i:i + 2] for i in range(0, len(digest), 2))


# ---------------------------------------------------------------------------
# Main decoder
# ---------------------------------------------------------------------------


def deeplink_to_config(uri: str) -> dict[str, Any]:
    if uri.startswith("tt://?"):
        encoded = uri[len("tt://?"):]
    elif uri.startswith("tt://"):
        encoded = uri[len("tt://"):]
    else:
        raise ValueError("URI must start with 'tt://'")

    padding = (4 - len(encoded) % 4) % 4
    payload = base64.urlsafe_b64decode(encoded + "=" * padding)
    if len(payload) > MAX_FIELD_BYTES:
        raise ValueError(f"decoded payload too large: {len(payload)} bytes")

    entries = parse_tlv(payload)
    cfg: dict = {
        "version": 0,
        "addresses": [],
        "has_ipv6": True,
        "skip_verification": False,
        "anti_dpi": False,
        "upstream_protocol": "http2",
    }
    cert_der: bytes | None = None

    for tag, value in entries:
        if tag == TAG_VERSION:
            v, _ = decode_varint(value, 0)
            cfg["version"] = v
        elif tag == TAG_HOSTNAME:
            cfg["hostname"] = value.decode()
        elif tag == TAG_ADDRESS:
            cfg["addresses"].append(value.decode())
        elif tag == TAG_CUSTOM_SNI:
            cfg["custom_sni"] = value.decode()
        elif tag == TAG_HAS_IPV6:
            cfg["has_ipv6"] = (value == b"\x01")
        elif tag == TAG_USERNAME:
            cfg["username"] = value.decode()
        elif tag == TAG_PASSWORD:
            cfg["password"] = value.decode()
        elif tag == TAG_SKIP_VERIFICATION:
            cfg["skip_verification"] = (value == b"\x01")
        elif tag == TAG_CERTIFICATE:
            if len(value) > MAX_CERT_BYTES:
                raise ValueError(f"embedded cert too large: {len(value)} bytes")
            cert_der = value
        elif tag == TAG_UPSTREAM_PROTOCOL:
            v, _ = decode_varint(value, 0)
            cfg["upstream_protocol"] = PROTOCOL_RMAP.get(v, "http2")
        elif tag == TAG_ANTI_DPI:
            cfg["anti_dpi"] = (value == b"\x01")
        elif tag == TAG_CLIENT_RANDOM_PREFIX:
            cfg["client_random_prefix"] = value.decode()
        elif tag == TAG_NAME:
            cfg["name"] = value.decode()
        elif tag == TAG_DNS_UPSTREAMS:
            arr: list[str] = []
            offset = 0
            while offset < len(value):
                slen, offset = decode_varint(value, offset)
                if slen > MAX_FIELD_BYTES or offset + slen > len(value):
                    raise ValueError("truncated string in dns_upstreams")
                arr.append(value[offset:offset + slen].decode())
                offset += slen
            cfg["dns_upstreams"] = arr
        # unknown tags ignored per DEEP_LINK.md forward-compat rule

    # Required fields
    for required in ("hostname", "username", "password"):
        if required not in cfg:
            raise ValueError(f"missing required field: {required}")
    if not cfg["addresses"]:
        raise ValueError("missing required field: addresses")

    if cert_der is not None:
        cfg["certificate_pem"] = der_to_pem(cert_der)
        cfg["fingerprint_sha256"] = fingerprint_sha256(cert_der)

    return cfg


def main() -> int:
    raw = sys.stdin.buffer.read(MAX_URI_BYTES + 1)
    if len(raw) > MAX_URI_BYTES:
        sys.stderr.write(f"error: URI exceeds {MAX_URI_BYTES}-byte cap\n")
        return 1
    uri = raw.decode("utf-8", errors="strict").strip()
    try:
        cfg = deeplink_to_config(uri)
    except (ValueError, UnicodeDecodeError) as e:
        sys.stderr.write(f"parse error: {e}\n")
        return 1
    json.dump(cfg, sys.stdout, separators=(",", ":"))
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
