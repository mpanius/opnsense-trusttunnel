#!/usr/local/bin/php
<?php

/*
 * Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
 * All rights reserved.
 * BSD-2-Clause — see LICENSE.
 *
 * --------------------------------------------------------------------------
 * materialize_certs.php — export the selected Trust-store cert + key to
 * plugin-owned PEM files on disk before render_server_config.py runs.
 *
 * Closes Codex finding #2 (cert_ref never becomes file paths) —
 * trusttunnel_endpoint loads `cert_chain_path` / `private_key_path` from
 * disk per CONFIGURATION.md:198-201, so the plugin must hydrate them.
 *
 * Idempotent: writes via tempfile + rename, preserves perms (cert 0644,
 * key 0600). Removes both files if cert_ref is empty.
 *
 * Exit codes:
 *   0  — success
 *   1  — config invalid (cert_ref points to a cert that no longer exists)
 *   2  — I/O failure
 * --------------------------------------------------------------------------
 */

require_once '/usr/local/etc/inc/config.inc';
require_once __DIR__ . '/cert_helpers.php';

const CERT_DIR  = '/usr/local/etc/trusttunnel/server/certs';
const CERT_PATH = CERT_DIR . '/cert.pem';
const KEY_PATH  = CERT_DIR . '/key.pem';

/**
 * Atomically write $content to $path with the given mode.
 * Mirrors render_server_config.py's _write_atomic semantics in PHP.
 */
function write_atomic(string $path, string $content, int $mode): void
{
    $tmp = $path . '.new';
    $fh = fopen($tmp, 'wb');
    if ($fh === false) {
        throw new RuntimeException("cannot open $tmp for write");
    }
    if (fwrite($fh, $content) !== strlen($content)) {
        fclose($fh);
        @unlink($tmp);
        throw new RuntimeException("short write to $tmp");
    }
    fflush($fh);
    fclose($fh);
    if (!chmod($tmp, $mode)) {
        @unlink($tmp);
        throw new RuntimeException("chmod($tmp, " . decoct($mode) . ") failed");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("rename($tmp -> $path) failed");
    }
}

/**
 * Find a <cert> node by refid under the legacy <cert> root list.
 * Returns null if not found.
 */
function find_cert_by_refid($config, string $refid)
{
    if (!isset($config['cert']) || !is_array($config['cert'])) {
        return null;
    }
    // OPNsense legacy <cert> is a list of associative arrays.
    foreach ($config['cert'] as $entry) {
        if (isset($entry['refid']) && $entry['refid'] === $refid) {
            return $entry;
        }
    }
    return null;
}

// --- main -----------------------------------------------------------------

try {
    // mkdir -p with strict mode
    if (!is_dir(CERT_DIR)) {
        if (!mkdir(CERT_DIR, 0750, true)) {
            fwrite(STDERR, "error: cannot create " . CERT_DIR . "\n");
            exit(2);
        }
    }
    @chmod(CERT_DIR, 0750);

    // Read OPNsense plugin model state from /conf/config.xml.
    global $config;
    if (!isset($config['OPNsense']['trusttunnel']['server'])) {
        fwrite(STDERR, "error: <OPNsense><trusttunnel><server> missing in config.xml\n");
        exit(1);
    }
    $server = $config['OPNsense']['trusttunnel']['server'];
    $refid  = isset($server['cert_ref']) ? trim((string)$server['cert_ref']) : '';

    if ($refid === '') {
        // No cert selected — clean up any previously materialized files.
        @unlink(CERT_PATH);
        @unlink(KEY_PATH);
        echo "no cert_ref selected; cleared materialized PEM files\n";
        exit(0);
    }

    $cert = find_cert_by_refid($config, $refid);
    if ($cert === null) {
        fwrite(STDERR, "error: cert refid=$refid not found in /conf/config.xml <cert> list\n");
        exit(1);
    }
    $crt_b64 = $cert['crt'] ?? '';
    $prv_b64 = $cert['prv'] ?? '';
    if ($crt_b64 === '' || $prv_b64 === '') {
        fwrite(STDERR, "error: cert refid=$refid has empty crt or prv field\n");
        exit(1);
    }
    try {
        $crt_pem = build_certificate_chain($config, $cert, $refid);
    } catch (UnexpectedValueException $e) {
        fwrite(STDERR, "error: " . $e->getMessage() . "\n");
        exit(1);
    }
    $prv_pem = base64_decode($prv_b64, true);
    if ($prv_pem === false) {
        fwrite(STDERR, "error: base64_decode failed for cert refid=$refid\n");
        exit(1);
    }
    // Sanity-check PEM headers — fail loud if data is corrupt rather than
    // letting trusttunnel_endpoint fail with a cryptic load error.
    if (strpos($crt_pem, '-----BEGIN CERTIFICATE-----') === false) {
        fwrite(STDERR, "error: cert refid=$refid is not a PEM-wrapped certificate\n");
        exit(1);
    }
    if (
        strpos($prv_pem, '-----BEGIN PRIVATE KEY-----') === false &&
        strpos($prv_pem, '-----BEGIN RSA PRIVATE KEY-----') === false &&
        strpos($prv_pem, '-----BEGIN EC PRIVATE KEY-----') === false
    ) {
        fwrite(STDERR, "error: cert refid=$refid private key is not a PEM-wrapped key\n");
        exit(1);
    }

    write_atomic(CERT_PATH, $crt_pem, 0644);
    write_atomic(KEY_PATH,  $prv_pem, 0600);

    echo "materialized cert and key for refid=$refid -> " . CERT_PATH . " / " . KEY_PATH . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "I/O error: " . $e->getMessage() . "\n");
    exit(2);
}
