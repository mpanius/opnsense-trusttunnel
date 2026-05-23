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
