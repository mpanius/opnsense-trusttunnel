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
 * DeeplinkController — server-side deeplink export (Task 8) and client-side
 * import (Task 10).
 *
 * Export: drives the `server.export_deeplink` configd action, which invokes
 *         deeplink_export.py and returns {uri, qr_png_base64} JSON.
 * Import: previewAction() runs deeplink_parse.py over a user-pasted URI via
 *         proc_open (NOT configd, see Codex finding #4). confirmImportAction()
 *         atomically writes a new server row + sets active_server.
 * --------------------------------------------------------------------------
 */

namespace OPNsense\TrustTunnel\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;

class DeeplinkController extends ApiControllerBase
{
    /**
     * Export the tt://?... deeplink + QR PNG for a named user.
     *
     * POST {"username": "alice", "name": "My VPN"}
     *
     * Returns JSON: {"status":"ok","uri":"tt://?...","qr_png_base64":"..."}
     * or {"status":"failed","error":"..."}.
     */
    public function exportAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        $username = trim((string)$this->request->getPost('username', ''));

        // Defense-in-depth: ServerController already enforces this regex
        // at the model layer, but if config.xml is hand-edited we'd
        // still pass the value to a configd parameter substitution.
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $username)) {
            return ['status' => 'failed', 'error' => 'invalid username'];
        }

        $backend = new Backend();
        $raw = $backend->configdpRun('trusttunnel server.export_deeplink', [$username]);
        $raw = trim((string)$raw);
        if ($raw === '') {
            return ['status' => 'failed', 'error' => 'deeplink_export.py produced no output'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['uri'])) {
            return [
                'status' => 'failed',
                'error'  => 'deeplink_export.py returned non-JSON or missing uri',
                'raw'    => $raw,
            ];
        }

        // v1: URI omits the optional <name> TLV. A future task may add a
        // --name option via a separate configd action; for now the user
        // edits the display name in their client app after import.
        $resp = [
            'status' => 'ok',
            'uri'    => (string)$decoded['uri'],
        ];
        if (isset($decoded['qr_png_base64'])) {
            $resp['qr_png_base64'] = (string)$decoded['qr_png_base64'];
        }
        return $resp;
    }

    // previewAction() + confirmImportAction() — Task 10.
}
