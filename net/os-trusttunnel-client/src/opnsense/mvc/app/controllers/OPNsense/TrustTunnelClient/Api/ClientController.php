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

namespace OPNsense\TrustTunnelClient\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;

class ClientController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = 'OPNsense\TrustTunnelClient\TrustTunnelClient';
    protected static $internalModelName = 'trusttunnelclient';

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

    protected function validateRuntimeInterfaces(): ?string
    {
        $client = $this->getModel()->client;
        $tun = trim((string)$client->tun_interface);
        $boundIf = trim((string)$client->bound_if);
        $useExisting = (string)$client->use_existing === '1';
        $interfaces = preg_split(
            '/\s+/',
            trim((string)@shell_exec('/sbin/ifconfig -l 2>/dev/null')),
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        $interfaces = is_array($interfaces) ? $interfaces : [];

        if ($useExisting && $tun === '') {
            return 'use_existing requires a non-empty tun_interface';
        }
        if ($tun !== '') {
            $exists = in_array($tun, $interfaces, true);
            if ($useExisting && !$exists) {
                return sprintf("tun_interface '%s' does not exist", $tun);
            }
            if (!$useExisting && $exists) {
                return sprintf(
                    "tun_interface '%s' already exists; enable use_existing or choose a free name",
                    $tun
                );
            }
        }
        if ($boundIf === '') {
            return 'bound_if is required on FreeBSD/OPNsense';
        }
        if (!in_array($boundIf, $interfaces, true)) {
            return sprintf("bound_if '%s' does not exist", $boundIf);
        }
        return null;
    }

    /**
     * Trigger client.reconfigure (render_client_config.py + rc.d restart).
     */
    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        $validationError = $this->validateRuntimeInterfaces();
        if ($validationError !== null) {
            return ['status' => 'failed', 'error' => $validationError];
        }
        $backend = new Backend();
        $output = trim((string)$backend->configdRun('trusttunnelclient client reconfigure'));
        $status = preg_match('/(?:^|\R)OK(?:\R|$)/', $output) === 1 ? 'ok' : 'failed';
        return ['status' => $status, 'output' => $output];
    }
}
