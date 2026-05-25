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

namespace OPNsense\TrustTunnelClient\Api;

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
    /**
     * Run deeplink_parse.py with the URI as stdin and a hard wall-clock
     * timeout that terminates the child if `proc_close` would otherwise
     * block. Returns ['ok' => bool, 'data' => array, 'error' => string].
     *
     * Closes Claude must_fix #2: stream_set_timeout only affects blocking
     * reads, not proc_close. We enforce a real timeout via stream_select
     * + proc_terminate; the webserver never blocks on a CPU-spinning child.
     */
    private function runDeeplinkParse(string $uri, int $timeoutSec = 10): array
    {
        if ($uri === '') {
            return ['ok' => false, 'error' => 'uri is required'];
        }
        if (strlen($uri) > 65536) {
            return ['ok' => false, 'error' => 'URI exceeds 64 KB cap', 'http' => 413];
        }
        if (strncmp($uri, 'tt://', 5) !== 0) {
            return ['ok' => false, 'error' => "URI must start with 'tt://'"];
        }

        $script = '/usr/local/opnsense/scripts/trusttunnelclient/deeplink_parse.py';
        $descspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(
            ['/usr/local/bin/python3', $script],
            $descspec,
            $pipes,
            null,
            ['LANG' => 'C.UTF-8']
        );
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'cannot spawn deeplink_parse.py'];
        }
        @fwrite($pipes[0], $uri);
        @fclose($pipes[0]);
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $timeoutSec;
        $stdout = '';
        $stderr = '';
        $killed = false;
        while (true) {
            $status = proc_get_status($proc);
            // Drain any output ready now.
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) { $stdout .= $chunk; }
            $chunk = stream_get_contents($pipes[2]);
            if ($chunk !== false) { $stderr .= $chunk; }
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                // Hard kill the child; proc_close will then return quickly.
                @proc_terminate($proc, 9);
                $killed = true;
                break;
            }
            usleep(50000);  // 50 ms
        }
        // Final drain.
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false) { $stdout .= $chunk; }
        $chunk = stream_get_contents($pipes[2]);
        if ($chunk !== false) { $stderr .= $chunk; }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $rc = proc_close($proc);

        if ($killed) {
            return ['ok' => false, 'error' => 'parser timed out (10s)', 'http' => 504];
        }
        if ($rc !== 0) {
            return ['ok' => false, 'error' => 'parser failed: ' . trim($stderr), 'rc' => $rc];
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'parser produced non-JSON', 'raw' => trim($stdout)];
        }
        return ['ok' => true, 'data' => $decoded];
    }

    /**
     * Parse a user-pasted URI and return decoded fields for the trust-gate
     * modal. NEVER touches config.xml.
     */
    public function previewAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        $uri = (string)$this->request->getPost('uri', '');
        $r = $this->runDeeplinkParse($uri);
        if (!$r['ok']) {
            $this->response->setStatusCode($r['http'] ?? 400);
            return ['status' => 'failed', 'error' => $r['error']];
        }
        return ['status' => 'ok', 'preview' => $r['data']];
    }

    /**
     * Atomically save the URI-parsed payload as a new <client><servers><server>
     * row AND set <client><active_server> to its uuid.
     *
     * Closes Claude must_fix #1 (trust-gate binding): the frontend posts the
     * **URI** here, not the previewed JSON. We re-parse via the SAME
     * deeplink_parse.py — what gets saved is what the parser produces from
     * the URI bytes. Client cannot bypass the trust gate by forging fields.
     */
    public function confirmImportAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }
        $body = json_decode((string)$this->request->getRawBody(), true);
        $uri = '';
        if (is_array($body) && isset($body['uri'])) {
            $uri = (string)$body['uri'];
        } else {
            $uri = (string)$this->request->getPost('uri', '');
        }
        $r = $this->runDeeplinkParse($uri);
        if (!$r['ok']) {
            $this->response->setStatusCode($r['http'] ?? 400);
            return ['status' => 'failed', 'error' => $r['error']];
        }
        $payload = $r['data'];

        // Defense-in-depth: re-validate the username regex on the server side.
        $username = isset($payload['username']) ? (string)$payload['username'] : '';
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $username)) {
            return ['status' => 'failed', 'error' => 'invalid username in parsed payload'];
        }

        $mdl = new \OPNsense\TrustTunnelClient\TrustTunnelClient();
        $cfg = \OPNsense\Core\Config::getInstance();
        $cfg->lock();
        $newUuid = '';
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
            'fingerprint_sha256' => $payload['fingerprint_sha256'] ?? '',
        ];
    }
}
