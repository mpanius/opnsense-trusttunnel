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

use OPNsense\Base\BaseModel;

/**
 * Plugin model. Inherits the standard BaseModel behaviour; derivatives
 * (e.g., `getActiveServer()` lookup) are added in Tasks 6 and 9 as the
 * server and client subtrees fill out.
 */
class TrustTunnelClient extends BaseModel
{
}
