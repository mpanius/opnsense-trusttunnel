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
 * OPNsense plugin hooks for os-trusttunnel-client (client role only).
 *
 *   - plugins_services()    -> trusttunnelclient_services()
 *   - plugins_syslog()      -> trusttunnelclient_syslog()
 *   - plugins_devices()     -> trusttunnelclient_devices()
 *   - plugins_xmlrpc_sync() -> trusttunnelclient_xmlrpc_sync()
 *
 * The server role lives in the separate os-trusttunnel plugin; its hooks
 * (firewall rule, endpoint service, server syslog facility) are NOT here.
 * --------------------------------------------------------------------------
 */

/**
 * Register the client service in Services -> Services.
 */
function trusttunnelclient_services()
{
    $services = [];

    $cfg = \OPNsense\Core\Config::getInstance()->object();
    $clientEnabled = false;
    if (isset($cfg->OPNsense->trusttunnelclient)) {
        $tt = $cfg->OPNsense->trusttunnelclient;
        $clientEnabled = isset($tt->client->enabled) && (string)$tt->client->enabled === '1';
    }

    $services[] = [
        'description' => gettext('TrustTunnel Client'),
        'configd'     => [
            'restart' => ['trusttunnelclient client restart'],
            'start'   => ['trusttunnelclient client start'],
            'stop'    => ['trusttunnelclient client stop'],
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
 * Register the syslog-ng facility so System -> Log Files gets a
 * TrustTunnel Client tab. Must match the daemon(8) `-T` flag in
 * rc.d/trusttunnel_client.
 */
function trusttunnelclient_syslog()
{
    return [
        'trusttunnelclient' => [
            'facility' => ['trusttunnel-client'],
        ],
    ];
}

/**
 * Register the `tt<N>` virtual TUN device pattern with the
 * Interfaces -> Assignments dropdown. The device is created by
 * trusttunnel_client (a daemon-owned tun(4) on FreeBSD); OPNsense's
 * role is just surfacing it in the assignment UI.
 */
function trusttunnelclient_devices()
{
    return [
        [
            'configurable' => false,
            'pattern'      => '^tt[0-9]+$',
            'type'         => 'TrustTunnelClient',
            'volatile'     => true,
            'function'     => 'trusttunnelclient_prepare_interface',
        ],
    ];
}

/**
 * Per-device hook invoked by Interfaces when a tt<N> entry is selected.
 * No-op: trusttunnel_client(8) manages the tun device lifecycle.
 */
function trusttunnelclient_prepare_interface($iface)
{
    return null;
}

/**
 * HA cluster sync — ships the <OPNsense><trusttunnelclient> subtree to
 * the standby on Apply. Passwords on server rows ride on nosync="1" and
 * are stripped before the XMLRPC POST.
 */
function trusttunnelclient_xmlrpc_sync()
{
    return [
        [
            'description' => gettext('TrustTunnel Client'),
            'section'     => 'OPNsense.trusttunnelclient',
            'id'          => 'trusttunnelclient',
            'services'    => ['trusttunnel_client'],
        ],
    ];
}
