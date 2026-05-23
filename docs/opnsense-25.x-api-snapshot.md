OPNsense API snapshot — captured Sat May 23 02:24:32 UTC 2026
================================================

--- OPNsense / FreeBSD versions ---
OPNsense 26.1.8_5 (amd64)
14.3-RELEASE-p12

--- plugins.inc hook dispatcher line numbers (CE 26.1.8_5) ---
69:function plugins_services()
94:function plugins_devices()
151:function plugins_syslog()
245:function plugins_firewall($fw)
369:function plugins_xmlrpc_sync()

--- CertificateField field type — class declaration ---
<?php

/*
 * Copyright (C) 2015-2026 Deciso B.V.
 * All rights reserved.

use OPNsense\Core\Config;

/**
 * Class CertificateField field type to select certificates from the internal cert manager
 * package to glue legacy certificates into the model.
 * @package OPNsense\Base\FieldTypes
 */
class CertificateField extends BaseListField
{
    /**
     * @var string certificate type cert/ca, reflects config section to use as source
     */
    private $certificateType = 'cert';

    /**

--- Note: plugin_managed grep on core returned 0 hits ---
Confirms plan assumption: <plugin_managed> is a custom-tag pattern (os-acme-client uses it).
Our ServerController stores the canonical UUID in <server><firewall_rule_uuid> and uses the
tag as a discovery marker only. Compatible with CE 26.1.8_5 unchanged.
