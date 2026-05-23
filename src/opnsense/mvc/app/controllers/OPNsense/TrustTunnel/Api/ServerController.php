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
 * ServerController — API for the Server tab.
 *
 * Inherits ApiMutableModelControllerBase's standard *Action methods for
 * the <server>...<users>...<user> ArrayField. Custom actions added in
 * later tasks:
 *   - generateSelfSignedAction()  — Task 7 (PHP openssl_csr_new pipeline)
 *   - exportDeeplinkAction()      — Task 8 (delegates to DeeplinkController)
 *   - delUserAction()             — Task 11 (with reconfigure chain)
 *
 * setAction() is overridden here to manage the persistent <filter><rule>
 * entry whose UUID is stored in <server><firewall_rule_uuid> — Task 10
 * adds the body of the firewall sync helper.
 * --------------------------------------------------------------------------
 */

namespace OPNsense\TrustTunnel\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class ServerController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = 'OPNsense\TrustTunnel\TrustTunnel';
    protected static $internalModelName = 'trusttunnel';

    // --- Standard CRUD for the <users> ArrayField --------------------------

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
        // Task 11 wraps this in a reconfigure-on-success chain. For now,
        // the default delBase + caller-triggered reconfigure suffices.
        return $this->delBase('server.users.user', $uuid);
    }

    // --- Set the whole server section --------------------------------------

    /**
     * Override of set() to add post-save side-effects on the server section.
     *
     * Task 10 adds the firewall-rule sync here. For Task 6, this is just a
     * pass-through that calls the parent to write config and returns.
     */
    public function setAction()
    {
        // Pass through to parent set; firewall-rule sync wired in Task 10.
        return parent::setAction();
    }

    // --- Service convenience: reconfigure ---------------------------------

    /**
     * Trigger the server.reconfigure configd action chain (cert materialize
     * -> Python render -> rc.d restart). Distinct from ServiceController
     * (Task 7) which exposes start/stop/restart/status as their own POSTs;
     * this is the one-stop "apply" hook the frontend uses after set().
     */
    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        $backend = new Backend();
        $output = trim((string)$backend->configdRun('trusttunnel server.reconfigure'));
        $status = (strpos($output, 'OK') !== false || $output === '') ? 'ok' : 'failed';
        return ['status' => $status, 'output' => $output];
    }
}
