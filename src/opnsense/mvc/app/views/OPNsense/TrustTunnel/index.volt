{#
 # Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
 # All rights reserved.
 # BSD-2-Clause — see LICENSE in repo root.
 #}

<ul class="nav nav-tabs" id="trusttunnelTabs" role="tablist">
    <li role="presentation" class="active">
        <a data-toggle="tab" href="#server" aria-controls="server" role="tab">{{ lang._("Server") }}</a>
    </li>
    <li role="presentation">
        <a data-toggle="tab" href="#client" aria-controls="client" role="tab">{{ lang._("Client") }}</a>
    </li>
</ul>

<div class="tab-content content-box">
    <div id="server" class="tab-pane fade in active" role="tabpanel">
        {{ partial("OPNsense/TrustTunnel/server") }}
    </div>
    <div id="client" class="tab-pane fade" role="tabpanel">
        {{ partial("OPNsense/TrustTunnel/client") }}
    </div>
</div>

<script>
//<![CDATA[
$(function () {
    // URL-hash routing — mirrors vpn/wireguard/general.volt pattern.
    var hash = window.location.hash || '#server';
    $('#trusttunnelTabs a[href="' + hash + '"]').tab('show');

    $('#trusttunnelTabs a').on('shown.bs.tab', function (e) {
        window.history.replaceState(null, null, $(e.target).attr('href'));
    });
});
//]]>
</script>
