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
     * exists in <client><servers>.
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
        $this->save();
        return ['status' => 'ok', 'active_server' => $uuid];
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
        $output = trim((string)$backend->configdRun('trusttunnel client.reconfigure'));
        $status = (strpos($output, 'OK') !== false || $output === '') ? 'ok' : 'failed';
        return ['status' => $status, 'output' => $output];
    }
}
