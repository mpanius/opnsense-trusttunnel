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
 * ServiceController — generic start/stop/restart/status for both
 * trusttunnel_endpoint (server role) and trusttunnel_client (client
 * role). All POSTs route through configd actions defined in
 * service/conf/actions.d/actions_trusttunnel.conf.
 * --------------------------------------------------------------------------
 */

namespace OPNsense\TrustTunnelClient\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;

class ServiceController extends ApiControllerBase
{
    /**
     * Map UI-facing role token to configd action prefix.
     * Rejects everything else — prevents the controller from being a
     * generic configd shell.
     */
    private function resolveRole(string $role): string
    {
        if ($role !== 'client') {
            throw new \InvalidArgumentException("role must be 'client', got " . $role);
        }
        return $role;
    }

    private function dispatch(string $role, string $verb): array
    {
        $action = $this->resolveRole($role) . ' ' . $verb;
        $backend = new Backend();
        $output = trim((string)$backend->configdRun('trusttunnelclient ' . $action));
        return [
            'status' => 'ok',
            'role'   => $role,
            'verb'   => $verb,
            'output' => $output,
        ];
    }

    public function startAction($role = 'client')
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        return $this->dispatch($role, 'start');
    }

    public function stopAction($role = 'client')
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        return $this->dispatch($role, 'stop');
    }

    public function restartAction($role = 'client')
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        return $this->dispatch($role, 'restart');
    }

    public function reconfigureAction($role = 'client')
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        return $this->dispatch($role, 'reconfigure');
    }

    /**
     * Status is a GET — no side effects, OK to be idempotent.
     */
    public function statusAction($role = 'client')
    {
        try {
            $resolved = $this->resolveRole($role);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
        $backend = new Backend();
        $output = trim((string)$backend->configdRun('trusttunnelclient ' . $resolved . ' status'));
        // FreeBSD rc.d `onestatus` returns lines like "trusttunnel_endpoint is running as pid 1234."
        $running = (stripos($output, 'is running') !== false);
        return [
            'status'  => 'ok',
            'role'    => $role,
            'running' => $running,
            'output'  => $output,
        ];
    }
}
