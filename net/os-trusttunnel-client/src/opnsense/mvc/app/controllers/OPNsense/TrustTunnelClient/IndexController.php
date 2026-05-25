<?php

/*
 * Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the conditions of the
 * BSD-2-Clause license (see LICENSE in the repo root) are met.
 */

namespace OPNsense\TrustTunnelClient;

use OPNsense\Base\IndexController as BaseIndexController;

/**
 * GUI router for /ui/trusttunnelclient/ — renders the client view.
 */
class IndexController extends BaseIndexController
{
    public function indexAction()
    {
        $this->view->clientForm = $this->getForm('client');
        $this->view->peerForm   = $this->getForm('peer');
        $this->view->pick('OPNsense/TrustTunnelClient/index');
    }
}
