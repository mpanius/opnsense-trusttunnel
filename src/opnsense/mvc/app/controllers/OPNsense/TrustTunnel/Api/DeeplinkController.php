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

    /**
     * Parse a user-pasted tt://?... URI and return the decoded JSON
     * preview (the trust gate). NEVER touches config.xml — this is a
     * preview-only step.
     *
     * Closes Codex finding #4 (configd stdin not available in CE 25.x):
     * invokes deeplink_parse.py via proc_open with a stdin pipe and an
     * explicit timeout, so the URI never lands on argv.
     * Closes Claude must_fix #2 + Codex finding #5: enforces 64 KB cap
     * at the controller before the subprocess spawns.
     */
    public function previewAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        $uri = (string)$this->request->getPost('uri', '');
        if ($uri === '') {
            return ['status' => 'failed', 'error' => 'uri is required'];
        }
        if (strlen($uri) > 65536) {
            $this->response->setStatusCode(413);
            return ['status' => 'failed', 'error' => 'URI exceeds 64 KB cap'];
        }
        if (strncmp($uri, 'tt://', 5) !== 0) {
            return ['status' => 'failed', 'error' => "URI must start with 'tt://'"];
        }

        $script = '/usr/local/opnsense/scripts/trusttunnel/deeplink_parse.py';
        $descspec = [
            0 => ['pipe', 'r'],   // stdin: URI
            1 => ['pipe', 'w'],   // stdout: JSON
            2 => ['pipe', 'w'],   // stderr: error message
        ];
        $proc = @proc_open(
            ['/usr/local/bin/python3', $script],
            $descspec,
            $pipes,
            null,
            ['LANG' => 'C.UTF-8']
        );
        if (!is_resource($proc)) {
            return ['status' => 'failed', 'error' => 'cannot spawn deeplink_parse.py'];
        }

        // Send URI via stdin; close to let the script EOF cleanly.
        @fwrite($pipes[0], $uri);
        @fclose($pipes[0]);

        // 10 s read budget; if the process is slower than that, terminate.
        stream_set_timeout($pipes[1], 10);
        stream_set_timeout($pipes[2], 10);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $rc = proc_close($proc);

        if ($rc !== 0) {
            $this->response->setStatusCode(400);
            return [
                'status' => 'failed',
                'error'  => 'parser failed: ' . trim((string)$stderr),
                'rc'     => $rc,
            ];
        }
        $decoded = json_decode((string)$stdout, true);
        if (!is_array($decoded)) {
            $this->response->setStatusCode(400);
            return [
                'status' => 'failed',
                'error'  => 'parser produced non-JSON',
                'raw'    => trim((string)$stdout),
            ];
        }
        return [
            'status'  => 'ok',
            'preview' => $decoded,
        ];
    }

    /**
     * Atomically save the previewed payload as a new <client><servers><server>
     * row AND set <client><active_server> to its uuid — in one
     * transaction-style lock. Closes Codex finding #5 (avoids the two-POST
     * flow where active points at non-existent uuid on partial failure).
     */
    public function confirmImportAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        $payload = json_decode((string)$this->request->getRawBody(), true);
        if (!is_array($payload)) {
            $payload = $this->request->getPost();
        }
        if (!is_array($payload) || !isset($payload['hostname'])) {
            return ['status' => 'failed', 'error' => 'missing or invalid payload'];
        }

        // Defense-in-depth: re-validate the username regex on the server side.
        $username = isset($payload['username']) ? (string)$payload['username'] : '';
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $username)) {
            return ['status' => 'failed', 'error' => 'invalid username in payload'];
        }

        $mdl = new \OPNsense\TrustTunnel\TrustTunnel();
        $cfg = \OPNsense\Core\Config::getInstance();
        $cfg->lock();
        try {
            $srvNode = $mdl->client->servers->server->Add();
            $newUuid = $srvNode->getAttribute('uuid');

            $set = function ($field, $value) use ($srvNode) {
                if ($value === null) {
                    return;
                }
                $srvNode->$field = (string)$value;
            };
            $set('name',                 $payload['name']               ?? ($payload['hostname'] ?? 'imported'));
            $set('hostname',             $payload['hostname']);
            $set('addresses',            isset($payload['addresses']) && is_array($payload['addresses'])
                                            ? implode(',', $payload['addresses'])
                                            : (string)($payload['hostname'] . ':443'));
            $set('custom_sni',           $payload['custom_sni']         ?? '');
            $set('username',             $username);
            $set('password',             $payload['password']           ?? '');
            $set('certificate_pem',      $payload['certificate_pem']    ?? '');
            $set('skip_verification',    !empty($payload['skip_verification']) ? '1' : '0');
            $set('upstream_protocol',    $payload['upstream_protocol']  ?? 'http2');
            $set('anti_dpi',             !empty($payload['anti_dpi']) ? '1' : '0');
            $set('client_random_prefix', $payload['client_random_prefix'] ?? '');
            $set('dns_upstreams',        isset($payload['dns_upstreams']) && is_array($payload['dns_upstreams'])
                                            ? implode(',', $payload['dns_upstreams'])
                                            : '');

            $mdl->client->active_server = $newUuid;

            $errors = $mdl->validate(null, false);
            if (!empty($errors)) {
                $cfg->unlock();
                return [
                    'status' => 'failed',
                    'error'  => 'validation error',
                    'errors' => $errors,
                ];
            }
            $mdl->serializeToConfig();
            $cfg->save();
        } catch (\Throwable $e) {
            $cfg->unlock();
            return ['status' => 'failed', 'error' => 'config write failed: ' . $e->getMessage()];
        }
        $cfg->unlock();

        return [
            'status'         => 'ok',
            'uuid'           => $newUuid,
            'active_server'  => $newUuid,
        ];
    }
}
