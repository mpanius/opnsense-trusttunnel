<?php

/*
 * Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the conditions of the
 * BSD-2-Clause license (see LICENSE in the repo root) are met.
 */

namespace OPNsense\TrustTunnel;

use OPNsense\Base\IndexController as BaseIndexController;

/**
 * GUI router for /ui/trusttunnel/ — renders the two-tab Volt view.
 *
 * Server-side form data and bootgrid wiring are populated as Tasks 6 and 9
 * land. Right now the tabs render placeholder content.
 */
class IndexController extends BaseIndexController
{
    public function indexAction()
    {
        // Forms for the two tabs — populated in later tasks. The view checks
        // for null and shows the placeholder paragraph instead.
        $this->view->serverForm = null;
        $this->view->clientForm = null;
        $this->view->pick('OPNsense/TrustTunnel/index');
    }
}
