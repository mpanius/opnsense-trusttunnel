{#
 # Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
 # BSD-2-Clause — see LICENSE.
 # Client tab — servers bootgrid + Apply. Paste-deeplink import flow with
 # trust-gate preview modal is wired in Task 10.
 #}

<section class="page-content-main">
    <div class="container-fluid">
        <div class="row">
            <section class="col-xs-12">
                <h2>{{ lang._("Servers") }}</h2>
                <p class="help-block">
                    {{ lang._("Add servers via the Import deeplink flow below, or click + to enter the fields manually. One row at a time is Active; that row drives /usr/local/etc/trusttunnel/client/trusttunnel_client.toml on Apply.") }}
                </p>
                <table id="grid-servers"
                       class="table table-condensed table-hover table-striped table-responsive"
                       data-editAlert="ClientServerChangeMessage"
                       data-editDialog="DialogServer">
                    <thead>
                    <tr>
                        <th data-column-id="uuid" data-type="string" data-identifier="true" data-visible="false">{{ lang._("UUID") }}</th>
                        <th data-column-id="name" data-type="string">{{ lang._("Name") }}</th>
                        <th data-column-id="hostname" data-type="string">{{ lang._("Hostname") }}</th>
                        <th data-column-id="username" data-type="string">{{ lang._("Username") }}</th>
                        <th data-column-id="upstream_protocol" data-type="string">{{ lang._("Protocol") }}</th>
                        <th data-column-id="active" data-type="string" data-formatter="activeMarker" data-sortable="false">{{ lang._("Active") }}</th>
                        <th data-column-id="commands" data-width="11em" data-formatter="rowActions" data-sortable="false">{{ lang._("Actions") }}</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                    <tr>
                        <td></td>
                        <td colspan="5">
                            <button data-action="add" type="button" class="btn btn-primary btn-xs">
                                <span class="fa fa-plus fa-fw"></span>
                                <b>{{ lang._("Add server (manual)") }}</b>
                            </button>
                        </td>
                    </tr>
                    </tfoot>
                </table>
            </section>
        </div>

        <hr/>

        <div class="row">
            <section class="col-xs-12">
                <h2>{{ lang._("Import deeplink") }}</h2>
                <div class="alert alert-info" role="alert">
                    {{ lang._("Paste a tt://?... URI you exported from another TrustTunnel server. Import shows a trust-gate preview before saving — wired in plan task 10.") }}
                </div>
                <div class="form-group">
                    <label for="ImportUriBox">{{ lang._("Deeplink URI") }}</label>
                    <textarea id="ImportUriBox"
                              class="form-control"
                              rows="3"
                              maxlength="65536"
                              placeholder="tt://?..."></textarea>
                    <button type="button" class="btn btn-primary" id="ImportBtn" style="margin-top: .5em;" disabled>
                        <span class="fa fa-sign-in"></span> {{ lang._("Import (task 10)") }}
                    </button>
                </div>
            </section>
        </div>
    </div>
</section>

<script>
//<![CDATA[
$(function () {
    var gridOpts = {
        "activeMarker": function (column, row) {
            var active = (window.__tt_activeServer || '') === row.uuid;
            return active
                ? '<span class="label label-success">' + "{{ lang._('Active') }}" + '</span>'
                : '<button type="button" class="btn btn-xs btn-default tt-set-active" data-uuid="' + row.uuid + '">' + "{{ lang._('Set active') }}" + '</button>';
        },
        "rowActions": function (column, row) {
            var html = '<button type="button" class="btn btn-xs btn-default" data-action="edit" data-row-id="' + row.uuid + '"><span class="fa fa-pencil"></span></button> ';
            html    += '<button type="button" class="btn btn-xs btn-default" data-action="deleteSelected" data-row-id="' + row.uuid + '"><span class="fa fa-trash-o"></span></button>';
            return html;
        }
    };

    // Fetch active_server up-front; bootgrid renders against the cached value.
    ajaxGet('/api/trusttunnel/client/get/', {}, function (data) {
        if (data && data.trusttunnel && data.trusttunnel.client && data.trusttunnel.client.active_server) {
            window.__tt_activeServer = data.trusttunnel.client.active_server;
        }
        $("#grid-servers").UIBootgrid({
            search: '/api/trusttunnel/client/searchServer/',
            get:    '/api/trusttunnel/client/getServer/',
            set:    '/api/trusttunnel/client/setServer/',
            add:    '/api/trusttunnel/client/addServer/',
            del:    '/api/trusttunnel/client/delServer/',
            options: gridOpts
        });
    });

    $(document).on('click', '.tt-set-active', function (e) {
        e.preventDefault();
        var uuid = $(this).data('uuid');
        ajaxCall('/api/trusttunnel/client/setActive/' + uuid, {}, function (data) {
            if (data && data.status === 'ok') {
                window.__tt_activeServer = uuid;
                $("#grid-servers").bootgrid('reload');
            } else {
                BootstrapDialog.show({
                    title: "{{ lang._('Set active failed') }}",
                    type: BootstrapDialog.TYPE_DANGER,
                    message: (data && data.error) ? data.error : "{{ lang._('Unknown error') }}"
                });
            }
        });
    });

    // Client-side URI length cap mirrors the 64 KB backend cap (TS-009).
    $('#ImportUriBox').on('input', function () {
        var v = $(this).val();
        // Enable the import button once Task 10 lands; until then, keep disabled.
        $('#ImportBtn').prop('disabled', v.length === 0 || v.length > 65536);
        if (v.length > 65536) {
            BootstrapDialog.alert({
                title: "{{ lang._('URI too large') }}",
                type:  BootstrapDialog.TYPE_WARNING,
                message: "{{ lang._('Deeplink must be 65536 bytes or less.') }}"
            });
        }
    });
});
//]]>
</script>
