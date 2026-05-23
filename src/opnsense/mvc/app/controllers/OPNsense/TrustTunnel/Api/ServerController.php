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
        $result = $this->delBase('server.users.user', $uuid);
        // Auto-reconfigure on successful delete — closes the gap where a
        // revoked user could still authenticate until the operator
        // remembered to click Apply. Mirrors Task 6 reconfigure chain.
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

    // --- Set the whole server section --------------------------------------

    /**
     * Override of set() to manage the persistent <filter><rule> entry
     * whose UUID is stored in <server><firewall_rule_uuid>.
     *
     * On enabled flip: create or refresh the auto-rule.
     * On disabled flip: remove the auto-rule.
     * On unchanged: re-sync dst/dst_port if listen_address changed.
     */
    public function setAction()
    {
        $result = parent::setAction();
        if (is_array($result) && isset($result['result']) && $result['result'] === 'saved') {
            try {
                $this->syncFirewallRule();
            } catch (\Throwable $e) {
                // Don't fail the user's save on firewall-sync hiccups —
                // surface the issue but keep the model state intact.
                $result['firewall_warning'] = $e->getMessage();
            }
        }
        return $result;
    }

    /**
     * Sync the auto-firewall rule. UUID is stored in
     * <server><firewall_rule_uuid> and the rule itself is written into
     * the legacy <filter><rule> list with a <plugin_managed>os-trusttunnel
     * </plugin_managed> marker so we can find/refresh/delete it later.
     *
     * Closes Codex finding #1 (persistent rule, not runtime hook) and
     * Claude should_fix #5 (UUID-based identity, not description prefix).
     */
    private function syncFirewallRule(): void
    {
        $mdl = $this->getModel();
        $serverEnabled = (string)$mdl->server->enabled === '1';
        $listen = (string)$mdl->server->listen_address;
        $expectedUuid = trim((string)$mdl->server->firewall_rule_uuid);

        // Parse listen_address -> (ip, port).
        $port = 443;
        $ip   = '0.0.0.0';
        if ($listen !== '') {
            if (strpos($listen, '[') === 0) {
                $close = strpos($listen, ']');
                if ($close !== false) {
                    $ip   = substr($listen, 1, $close - 1);
                    $port = (int)substr($listen, $close + 2);
                }
            } elseif (strpos($listen, ':') !== false) {
                [$ip, $portStr] = explode(':', $listen, 2);
                $port = (int)$portStr;
            }
        }

        $cfg = \OPNsense\Core\Config::getInstance();
        $cfg->lock();
        try {
            $root = $cfg->object();
            if (!isset($root->filter)) {
                $root->addChild('filter');
            }
            $filter = $root->filter;

            // Collect all rules tagged plugin_managed=os-trusttunnel.
            $existing = [];
            foreach ($filter->rule as $rule) {
                if ((string)($rule->plugin_managed ?? '') === 'os-trusttunnel') {
                    $existing[] = $rule;
                }
            }

            if (!$serverEnabled) {
                foreach ($existing as $rule) {
                    $this->removeXmlNode($filter, $rule);
                }
                $mdl->server->firewall_rule_uuid = '';
                $mdl->serializeToConfig();
                $cfg->save();
                return;
            }

            // Server is enabled — find or create a matching rule.
            $canonical = null;
            $duplicates = [];
            foreach ($existing as $rule) {
                $uuid = (string)$rule['uuid'];
                if ($expectedUuid !== '' && $uuid === $expectedUuid) {
                    $canonical = $rule;
                } else {
                    $duplicates[] = $rule;
                }
            }
            if ($canonical === null) {
                // No UUID match — re-create. Drop ALL plugin_managed
                // duplicates first to keep a single canonical row.
                foreach ($duplicates as $rule) {
                    $this->removeXmlNode($filter, $rule);
                }
                $duplicates = [];
                $canonical = $filter->addChild('rule');
                $newUuid   = $this->generateUuid();
                $canonical->addAttribute('uuid', $newUuid);
                $canonical->addChild('plugin_managed', 'os-trusttunnel');
                $canonical->addChild('descr', 'Auto: TrustTunnel inbound (managed by os-trusttunnel; do not edit)');
                $canonical->addChild('type', 'pass');
                $canonical->addChild('interface', 'wan');
                $canonical->addChild('ipprotocol', 'inet');
                $canonical->addChild('protocol', 'tcp');
                $src = $canonical->addChild('source');
                $src->addChild('any', '1');
                $dst = $canonical->addChild('destination');
                if ($ip !== '' && $ip !== '0.0.0.0') {
                    $dst->addChild('address', $ip);
                } else {
                    $dst->addChild('any', '1');
                }
                $dst->addChild('port', (string)$port);

                $mdl->server->firewall_rule_uuid = $newUuid;
            } else {
                // Re-sync dst/dst_port; preserve user edits on other fields.
                if (isset($canonical->destination)) {
                    unset($canonical->destination);
                }
                $dst = $canonical->addChild('destination');
                if ($ip !== '' && $ip !== '0.0.0.0') {
                    $dst->addChild('address', $ip);
                } else {
                    $dst->addChild('any', '1');
                }
                $dst->addChild('port', (string)$port);
            }

            // Remove any extra plugin_managed rules — keep canonical only.
            foreach ($duplicates as $rule) {
                $this->removeXmlNode($filter, $rule);
            }

            $mdl->serializeToConfig();
            $cfg->save();
        } catch (\Throwable $e) {
            $cfg->unlock();
            throw $e;
        }
        $cfg->unlock();
    }

    /**
     * Remove a SimpleXMLElement child from its parent. SimpleXML doesn't
     * expose a clean remove API; the DOM detour is the canonical workaround.
     */
    private function removeXmlNode($parent, $child): void
    {
        $dom = dom_import_simplexml($child);
        if ($dom !== null && $dom->parentNode !== null) {
            $dom->parentNode->removeChild($dom);
        }
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
        $output = trim((string)$backend->configdRun('trusttunnel server reconfigure'));
        $status = (strpos($output, 'OK') !== false || $output === '') ? 'ok' : 'failed';
        return ['status' => $status, 'output' => $output];
    }

    // --- Self-signed certificate generator --------------------------------

    /**
     * Generate a self-signed certificate via PHP openssl_csr_new pipeline
     * and write it into System -> Trust as a new <cert> entry, then return
     * the new refid so the frontend can refresh CertificateField dropdown.
     *
     * Closes Claude must_fix #3 (fail-loud error chain) and should_fix #9
     * (entropy gate on freshly-booted VMs).
     */
    public function generateSelfSignedAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'error' => 'POST required'];
        }

        $commonName = (string)$this->request->getPost('common_name', '');
        $days       = (int)$this->request->getPost('days', 365);
        $sansRaw    = (string)$this->request->getPost('sans', '');
        $sans       = array_values(array_filter(array_map('trim', explode(',', $sansRaw))));

        // Input validation — fail-loud on bad inputs.
        if ($commonName === '' || strlen($commonName) > 64) {
            return ['status' => 'failed', 'error' => 'common_name must be 1-64 chars'];
        }
        if (!preg_match('/^[A-Za-z0-9._\-\* ]+$/', $commonName)) {
            return ['status' => 'failed', 'error' => 'common_name contains forbidden chars'];
        }
        if ($days < 1 || $days > 3650) {
            return ['status' => 'failed', 'error' => 'days must be between 1 and 3650'];
        }

        // Entropy gate (Claude should_fix #9). FreeBSD's fortuna re-seeds
        // very quickly on first boot but VM clones can stall — surface a
        // clear 503 rather than silently produce a weak key.
        $minpool = @shell_exec('/sbin/sysctl -n kern.random.fortuna.minpoolsize 2>/dev/null');
        if ($minpool !== null && trim((string)$minpool) === '0') {
            $this->response->setStatusCode(503);
            return [
                'status' => 'failed',
                'error'  => 'System entropy not yet ready. Wait 30s and retry.',
            ];
        }

        // openssl pipeline — every call's return is checked. On any false,
        // drain openssl_error_string() into errs[] and return HTTP 500.
        $errs = [];
        $drainErrs = function () use (&$errs) {
            while ($e = openssl_error_string()) {
                $errs[] = $e;
            }
        };

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg'       => 'sha256',
        ];

        $dn = ['commonName' => $commonName];

        $privkey = openssl_pkey_new($config);
        if ($privkey === false) {
            $drainErrs();
            $this->response->setStatusCode(500);
            return ['status' => 'failed', 'errors' => $errs ?: ['openssl_pkey_new returned false']];
        }

        $csr = openssl_csr_new($dn, $privkey, $config);
        if ($csr === false) {
            $drainErrs();
            $this->response->setStatusCode(500);
            return ['status' => 'failed', 'errors' => $errs ?: ['openssl_csr_new returned false']];
        }

        // SAN extensions: write a temporary openssl.cnf so the SAN ends up
        // in x509 v3 extensions. If sans is empty, sign without SANs.
        $signConfig = $config;
        $cnfPath = null;
        if (!empty($sans)) {
            $sanList = implode(',', array_map(function ($s) {
                return 'DNS:' . $s;
            }, $sans));
            $cnfPath = tempnam(sys_get_temp_dir(), 'tt_openssl_');
            $cnfBody = "[req]\ndistinguished_name = req_dn\nreq_extensions = v3_req\n"
                     . "[req_dn]\n"
                     . "[v3_req]\nsubjectAltName = " . $sanList . "\n";
            if (@file_put_contents($cnfPath, $cnfBody) === false) {
                $this->response->setStatusCode(500);
                return ['status' => 'failed', 'errors' => ['cannot stage SAN openssl.cnf']];
            }
            $signConfig['config'] = $cnfPath;
            $signConfig['x509_extensions'] = 'v3_req';
        }

        $x509 = openssl_csr_sign($csr, null, $privkey, $days, $signConfig);
        if ($cnfPath !== null) {
            @unlink($cnfPath);
        }
        if ($x509 === false) {
            $drainErrs();
            $this->response->setStatusCode(500);
            return ['status' => 'failed', 'errors' => $errs ?: ['openssl_csr_sign returned false']];
        }

        $crtPem = '';
        if (openssl_x509_export($x509, $crtPem) === false || $crtPem === '') {
            $drainErrs();
            $this->response->setStatusCode(500);
            return ['status' => 'failed', 'errors' => $errs ?: ['openssl_x509_export failed']];
        }

        $prvPem = '';
        if (openssl_pkey_export($privkey, $prvPem) === false || $prvPem === '') {
            $drainErrs();
            $this->response->setStatusCode(500);
            return ['status' => 'failed', 'errors' => $errs ?: ['openssl_pkey_export failed']];
        }

        // Sanity-check the PEM strings before they hit the Trust store.
        if (strpos($crtPem, '-----BEGIN CERTIFICATE-----') === false) {
            return ['status' => 'failed', 'errors' => ['exported certificate is not PEM']];
        }
        if (
            strpos($prvPem, '-----BEGIN PRIVATE KEY-----') === false &&
            strpos($prvPem, '-----BEGIN RSA PRIVATE KEY-----') === false
        ) {
            return ['status' => 'failed', 'errors' => ['exported private key is not PEM']];
        }

        // Append to /conf/config.xml <cert> via the OPNsense low-level
        // Config helper. Use uuid4 for refid (mirrors os-acme-client).
        try {
            $refid = $this->writeCertToTrustStore($commonName, $crtPem, $prvPem);
        } catch (\Throwable $e) {
            $this->response->setStatusCode(500);
            return ['status' => 'failed', 'errors' => ['Trust store write failed: ' . $e->getMessage()]];
        }

        return [
            'status'      => 'ok',
            'refid'       => $refid,
            'common_name' => $commonName,
            'days'        => $days,
        ];
    }

    /**
     * Append a new <cert> entry to /conf/config.xml under the legacy root
     * list, mirroring os-acme-client's Trust\Cert.php write pattern.
     * Returns the new refid (UUIDv4).
     */
    private function writeCertToTrustStore(string $commonName, string $crtPem, string $prvPem): string
    {
        // Use OPNsense's UUID helper if available; fall back to RFC 4122 v4.
        $refid = $this->generateUuid();

        $cfg = \OPNsense\Core\Config::getInstance();
        $cfg->lock();
        try {
            $xmlObj = $cfg->object();
            $cert = $xmlObj->addChild('cert');
            $cert->addChild('refid', $refid);
            $cert->addChild('descr', 'TrustTunnel self-signed (' . $commonName . ')');
            $cert->addChild('crt', base64_encode($crtPem));
            $cert->addChild('prv', base64_encode($prvPem));
            $cfg->save();
        } finally {
            $cfg->unlock();
        }
        return $refid;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
