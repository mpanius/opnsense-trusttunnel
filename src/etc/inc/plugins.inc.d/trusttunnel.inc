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
 * OPNsense plugin hooks for os-trusttunnel.
 *
 * Each function follows the OPNsense `plugins_<name>()` dispatcher contract.
 * Hooks populated in later plan tasks:
 *   - trusttunnel_services()      — Task 7 (Services → Services entries)
 *   - trusttunnel_syslog()        — Task 7 (System → Log Files tab)
 *   - trusttunnel_devices()       — Task 10 (Interfaces → Assignments tt<N>)
 *   - trusttunnel_xmlrpc_sync()   — Task 11 (HA / CARP cluster sync)
 *
 * Auto-firewall rule is NOT a hook here — it's persisted in <filter><rule>
 * directly by ServerController in Task 10 (see Codex finding #1).
 * --------------------------------------------------------------------------
 */

// Hook stubs intentionally left empty; the dispatcher tolerates missing
// functions, so an empty plugins.inc.d file is a valid no-op skeleton.
