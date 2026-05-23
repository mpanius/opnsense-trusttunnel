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
        $this->view->serverForm = $this->getForm('server');
        $this->view->userForm   = $this->getForm('user');
        $this->view->clientForm = $this->getForm('client');
        $this->view->peerForm   = $this->getForm('peer');
        $this->view->pick('OPNsense/TrustTunnel/index');
    }
}
