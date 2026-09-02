<?php

/*
 * Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
 * BSD-2-Clause — see LICENSE.
 */

/**
 * Find a legacy OPNsense list entry by refid.
 */
function find_legacy_entry_by_refid(array $config, string $list, string $refid)
{
    if (!isset($config[$list]) || !is_array($config[$list])) {
        return null;
    }
    foreach ($config[$list] as $entry) {
        if (isset($entry['refid']) && $entry['refid'] === $refid) {
            return $entry;
        }
    }
    return null;
}

/**
 * Decode a PEM certificate field and fail loud on corrupt Trust-store data.
 */
function decode_certificate_pem(string $encoded, string $label): string
{
    $pem = base64_decode($encoded, true);
    if ($pem === false || strpos($pem, '-----BEGIN CERTIFICATE-----') === false) {
        throw new UnexpectedValueException("$label is not a base64 PEM certificate");
    }
    return rtrim($pem) . "\n";
}

/**
 * Build the endpoint chain from the selected leaf and its OPNsense caref.
 * A CA object may itself contain an intermediate/root bundle.
 */
function build_certificate_chain(array $config, array $cert, string $refid): string
{
    $chain = decode_certificate_pem((string)($cert['crt'] ?? ''), "cert refid=$refid crt");
    $caref = trim((string)($cert['caref'] ?? ''));
    $visited = [];
    while ($caref !== '') {
        if (isset($visited[$caref])) {
            throw new UnexpectedValueException(
                "CA caref cycle at refid=$caref for cert refid=$refid"
            );
        }
        $visited[$caref] = true;

        $ca = find_legacy_entry_by_refid($config, 'ca', $caref);
        if ($ca === null) {
            throw new UnexpectedValueException(
                "CA refid=$caref not found for cert refid=$refid"
            );
        }
        $chain .= decode_certificate_pem(
            (string)($ca['crt'] ?? ''),
            "CA refid=$caref crt"
        );
        $caref = trim((string)($ca['caref'] ?? ''));
    }
    return $chain;
}
