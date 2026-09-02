<?php

/*
 * Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the conditions of the
 * BSD-2-Clause license (see LICENSE in the repo root) are met.
 *
 * ServerController owns only the TrustTunnel model and service apply path.
 * Certificates and firewall rules belong to their native OPNsense
 * subsystems and must be managed through /api/trust/cert/* and
 * /api/firewall/filter/* by the deployment workflow.
 */

namespace OPNsense\TrustTunnel\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class ServerController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = 'OPNsense\TrustTunnel\TrustTunnel';
    protected static $internalModelName = 'trusttunnel';

    public function searchUserAction()
    {
        return $this->searchBase('server.users.user', ['username'], 'username');
    }

    public function getUserAction($uuid = null)
    {
        return $this->getBase('user', 'server.users.user', $uuid);
    }

    public function addUserAction()
    {
        return $this->addBase('user', 'server.users.user');
    }

    public function setUserAction($uuid)
    {
        return $this->setBase('user', 'server.users.user', $uuid);
    }

    public function delUserAction($uuid)
    {
        $result = $this->delBase('server.users.user', $uuid);
        if (is_array($result) && isset($result['result']) && $result['result'] === 'deleted') {
            try {
                $backend = new Backend();
                $backend->configdRun('trusttunnel server reconfigure');
                $result['reconfigured'] = true;
            } catch (\Throwable $e) {
                $result['reconfigure_warning'] = $e->getMessage();
            }
        }
        return $result;
    }

    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        $backend = new Backend();
        $output = trim((string)$backend->configdRun('trusttunnel server reconfigure'));
        $status = preg_match('/(?:^|\R)OK(?:\R|$)/', $output) === 1 ? 'ok' : 'failed';
        return ['status' => $status, 'output' => $output];
    }
}
