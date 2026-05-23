<?php

/*
 * Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the conditions of the
 * BSD-2-Clause license (see LICENSE in the repo root) are met.
 *
 * --------------------------------------------------------------------------
 * OPNsense plugin hooks for os-trusttunnel.
 *
 * Hook contracts (called by /usr/local/etc/inc/plugins.inc dispatchers):
 *   - plugins_services()     -> trusttunnel_services()
 *   - plugins_syslog()       -> trusttunnel_syslog()
 *   - plugins_devices()      -> trusttunnel_devices()         (Task 10)
 *   - plugins_xmlrpc_sync()  -> trusttunnel_xmlrpc_sync()     (Task 11)
 *
 * Auto-firewall rule is NOT a hook here — it's persisted directly into
 * /conf/config.xml <filter><rule> by ServerController in Task 10 (see
 * Codex finding #1).
 * --------------------------------------------------------------------------
 */

/**
 * Register the two services in Services -> Services so the user gets a
 * uniform start/stop/restart UI per OPNsense convention.
 */
function trusttunnel_services()
{
    $services = [];

    $cfg = \OPNsense\Core\Config::getInstance()->object();
    $serverEnabled = false;
    $clientEnabled = false;
    if (isset($cfg->OPNsense->trusttunnel)) {
        $tt = $cfg->OPNsense->trusttunnel;
        $serverEnabled = isset($tt->server->enabled) && (string)$tt->server->enabled === '1';
        $clientEnabled = isset($tt->client->enabled) && (string)$tt->client->enabled === '1';
    }

    $services[] = [
        'description' => gettext('TrustTunnel Endpoint (server)'),
        'configd'     => [
            'restart' => ['trusttunnel server.restart'],
            'start'   => ['trusttunnel server.start'],
            'stop'    => ['trusttunnel server.stop'],
        ],
        'name'        => 'trusttunnel_endpoint',
        'pidfile'     => '/var/run/trusttunnel_endpoint.pid',
        'php'         => [
            'restart' => [],
            'start'   => [],
        ],
        'enabled'     => $serverEnabled,
        'nocheck'     => false,
    ];

    $services[] = [
        'description' => gettext('TrustTunnel Client'),
        'configd'     => [
            'restart' => ['trusttunnel client.restart'],
            'start'   => ['trusttunnel client.start'],
            'stop'    => ['trusttunnel client.stop'],
        ],
        'name'        => 'trusttunnel_client',
        'pidfile'     => '/var/run/trusttunnel_client.pid',
        'php'         => [
            'restart' => [],
            'start'   => [],
        ],
        'enabled'     => $clientEnabled,
        'nocheck'     => false,
    ];

    return $services;
}

/**
 * Register syslog-ng facilities so System -> Log Files gets a TrustTunnel
 * tab combining server + client output.
 *
 * The `facility` strings are the program names used in syslog-ng's filter.
 * They MUST match the daemon(8) `-T` flag in rc.d/trusttunnel_{endpoint,client}.
 */
function trusttunnel_syslog()
{
    return [
        'trusttunnel' => [
            'facility' => ['trusttunnel-server', 'trusttunnel-client'],
        ],
    ];
}

/**
 * Register the `tt<N>` virtual TUN device pattern with the
 * Interfaces -> Assignments dropdown. Mirrors wireguard_devices() and
 * tailscale_devices(); the actual device is created by trusttunnel_client
 * (a daemon-owned tun(4) on FreeBSD) so OPNsense's role is just
 * surfacing it in the assignment UI.
 */
function trusttunnel_devices()
{
    return [
        [
            'configurable' => false,
            'pattern'      => '^tt[0-9]+$',
            'type'         => 'TrustTunnel',
            'volatile'     => true,
            'function'     => 'trusttunnel_prepare_interface',
        ],
    ];
}

/**
 * Per-device hook invoked by Interfaces when a tt<N> entry is selected.
 * For v1 there is no per-device prep (the daemon brings the iface up);
 * keep as a no-op stub so the dispatcher can call it without warning.
 */
function trusttunnel_prepare_interface($iface)
{
    // intentionally no-op; trusttunnel_client(8) manages the tun device.
    return null;
}

// trusttunnel_xmlrpc_sync() — Task 11.
