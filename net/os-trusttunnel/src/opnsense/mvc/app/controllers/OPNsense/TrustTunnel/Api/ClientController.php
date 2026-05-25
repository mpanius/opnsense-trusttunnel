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
 * ClientController — API for the Client tab.
 *
 * Standard CRUD for the <client><servers><server> ArrayField (one row per
 * known endpoint). active_server is a separate UUIDField that picks which
 * row drives trusttunnel_client.toml on reconfigure.
 * --------------------------------------------------------------------------
 */

namespace OPNsense\TrustTunnel\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;

class ClientController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = 'OPNsense\TrustTunnel\TrustTunnel';
    protected static $internalModelName = 'trusttunnel';

    // --- CRUD on client.servers.server -------------------------------------

    public function searchServerAction()
    {
        return $this->searchBase('client.servers.server', ['name', 'hostname', 'username'], 'name');
    }

    public function getServerAction($uuid = null)
    {
        return $this->getBase('server', 'client.servers.server', $uuid);
    }

    public function addServerAction()
    {
        return $this->addBase('server', 'client.servers.server');
    }

    public function setServerAction($uuid)
    {
        return $this->setBase('server', 'client.servers.server', $uuid);
    }

    public function delServerAction($uuid)
    {
        return $this->delBase('client.servers.server', $uuid);
    }

    /**
     * Set active_server to the given uuid. Validates the uuid actually
     * exists in <client><servers> AND atomically writes via the same
     * Config::lock pattern DeeplinkController uses (parent class does
     * not expose a public save() — that was the original bug).
     */
    public function setActiveAction($uuid = null)
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        $uuid = trim((string)$uuid);
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            return ['status' => 'failed', 'error' => 'invalid uuid'];
        }

        $mdl = $this->getModel();
        $found = false;
        foreach ($mdl->client->servers->server->iterateItems() as $rowUuid => $row) {
            if ($rowUuid === $uuid) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return ['status' => 'failed', 'error' => "server with uuid={$uuid} not found"];
        }

        $mdl->client->active_server = $uuid;
        $cfg = \OPNsense\Core\Config::getInstance();
        $cfg->lock();
        try {
            $mdl->serializeToConfig();
            $cfg->save();
        } catch (\Throwable $e) {
            $cfg->unlock();
            return ['status' => 'failed', 'error' => 'config write failed: ' . $e->getMessage()];
        }
        $cfg->unlock();
        return ['status' => 'ok', 'active_server' => $uuid];
    }

    /**
     * Override of set() to add tun_interface clash detection (Claude
     * must_fix #3 + plan Task 9 § Key Decisions). Calls parent first
     * for model-layer validation + save, then runs a live `ifconfig -l`
     * check against the saved tun_interface name and rejects if it
     * clashes with a non-trusttunnel interface.
     */
    public function setAction()
    {
        $result = parent::setAction();
        if (!is_array($result) || ($result['result'] ?? '') !== 'saved') {
            return $result;
        }
        $tun = (string)$this->getModel()->client->tun_interface;
        if ($tun === '') {
            return $result;
        }
        // tt[0-9]+ pattern reserved for this plugin. Anything else colliding
        // with an existing interface is a config error.
        if (!preg_match('/^tt[0-9]+$/', $tun)) {
            $existing = trim((string)@shell_exec('/sbin/ifconfig -l 2>/dev/null'));
            foreach (preg_split('/\s+/', $existing) as $iface) {
                if ($iface === $tun) {
                    $result['tun_interface_warning'] = sprintf(
                        "tun_interface '%s' clashes with an existing live interface. " .
                        "Apply will likely fail. Use the default 'tt0' or a unique 'tt<N>' name.",
                        $tun
                    );
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Trigger client.reconfigure (render_client_config.py + rc.d restart).
     */
    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        $backend = new Backend();
        $output = trim((string)$backend->configdRun('trusttunnel client reconfigure'));
        $status = (strpos($output, 'OK') !== false || $output === '') ? 'ok' : 'failed';
        return ['status' => $status, 'output' => $output];
    }
}
