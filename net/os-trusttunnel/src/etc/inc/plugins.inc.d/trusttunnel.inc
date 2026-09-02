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
 * OPNsense plugin hooks for os-trusttunnel (server role only).
 *
 *   - plugins_services()    -> trusttunnel_services()
 *   - plugins_syslog()      -> trusttunnel_syslog()
 *   - plugins_xmlrpc_sync() -> trusttunnel_xmlrpc_sync()
 *
 * The client role lives in the separate os-trusttunnel-client plugin; its
 * hooks (TUN device, client service, client syslog facility) are NOT here.
 *
 * Certificates and firewall rules stay owned by the native OPNsense
 * subsystems and are not created or removed by this plugin hook.
 * --------------------------------------------------------------------------
 */

/**
 * Register the endpoint service in Services -> Services.
 */
function trusttunnel_services()
{
    $services = [];

    $cfg = \OPNsense\Core\Config::getInstance()->object();
    $serverEnabled = false;
    if (isset($cfg->OPNsense->trusttunnel)) {
        $tt = $cfg->OPNsense->trusttunnel;
        $serverEnabled = isset($tt->server->enabled) && (string)$tt->server->enabled === '1';
    }

    $services[] = [
        'description' => gettext('TrustTunnel Endpoint (server)'),
        'configd'     => [
            'restart' => ['trusttunnel server restart'],
            'start'   => ['trusttunnel server start'],
            'stop'    => ['trusttunnel server stop'],
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

    return $services;
}

/**
 * Register the syslog-ng facility so System -> Log Files gets a
 * TrustTunnel server tab. Must match the daemon(8) `-T` flag in
 * rc.d/trusttunnel_endpoint.
 */
function trusttunnel_syslog()
{
    return [
        'trusttunnel' => [
            'facility' => ['trusttunnel-server'],
        ],
    ];
}

/**
 * HA cluster sync — ships the <OPNsense><trusttunnel> subtree to the
 * standby on Apply. User passwords ride on nosync="1" and are stripped
 * before the XMLRPC POST.
 */
function trusttunnel_xmlrpc_sync()
{
    return [
        [
            'description' => gettext('TrustTunnel'),
            'section'     => 'OPNsense.trusttunnel',
            'id'          => 'trusttunnel',
            'services'    => ['trusttunnel_endpoint'],
        ],
    ];
}
