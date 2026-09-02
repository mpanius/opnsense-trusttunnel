OPNsense API snapshot — historical, captured Sat May 23 02:24:32 UTC 2026

Этот snapshot сохранён как архив разработки для OPNsense 26.1.8_5 и не
подтверждает совместимость текущей цели OPNsense 26.7.
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

--- Historical note: plugin_managed grep on core returned 0 hits ---
Это подтвердило, что `plugin_managed` не является контрактом штатного
Firewall API. Экспериментальная реализация с прямой записью legacy rule была
удалена до выпуска `2.1.0`; текущий plugin не хранит
`server.firewall_rule_uuid` и не изменяет firewall при Apply/uninstall.
