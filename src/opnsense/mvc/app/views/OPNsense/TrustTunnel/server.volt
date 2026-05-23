{#
 # Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
 # BSD-2-Clause — see LICENSE.
 # Server tab — bootgrid for users + per-user Export Deeplink modal (QR).
 # Server settings form, self-signed cert generation modal, firewall
 # rule status will be wired in Tasks 9-11.
 #}

<div class="alert alert-info" role="alert">
    {{ lang._("Server tab — Manage TrustTunnel endpoint users and export per-user deeplinks. Settings form (listen address, hostname, cert, protocols) is wired in subsequent tasks.") }}
</div>

<section class="page-content-main">
    <div class="container-fluid">
        <div class="row">
            <section class="col-xs-12">
                <h2>{{ lang._("Users") }}</h2>
                <table id="grid-users"
                       class="table table-condensed table-hover table-striped table-responsive"
                       data-editAlert="ServerUserChangeMessage"
                       data-editDialog="DialogUser">
                    <thead>
                    <tr>
                        <th data-column-id="uuid" data-type="string" data-identifier="true" data-visible="false">{{ lang._("UUID") }}</th>
                        <th data-column-id="username" data-type="string">{{ lang._("Username") }}</th>
                        <th data-column-id="commands" data-width="11em" data-formatter="rowActions" data-sortable="false">{{ lang._("Actions") }}</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                    <tr>
                        <td></td>
                        <td>
                            <button data-action="add" type="button" class="btn btn-primary btn-xs">
                                <span class="fa fa-plus fa-fw"></span>
                                <b>{{ lang._("Add user") }}</b>
                            </button>
                        </td>
                    </tr>
                    </tfoot>
                </table>
            </section>
        </div>
    </div>
</section>

{# --- Export Deeplink modal --- #}
<div id="DialogDeeplink" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ lang._("Export deeplink") }} — <span id="DeeplinkUserName"></span></h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>{{ lang._("Deeplink URI") }}</label>
                    <textarea id="DeeplinkUri" class="form-control" rows="3" readonly></textarea>
                    <button type="button" class="btn btn-default btn-xs" id="DeeplinkCopyBtn" style="margin-top: .5em;">
                        <span class="fa fa-copy"></span> {{ lang._("Copy URI") }}
                    </button>
                </div>
                <div class="form-group text-center">
                    <label>{{ lang._("QR code") }}</label><br>
                    <img id="DeeplinkQrImg" alt="QR" style="max-width: 300px; height: auto;"/>
                    <br>
                    <a id="DeeplinkDownloadBtn" download="trusttunnel-deeplink.png" class="btn btn-default btn-xs" style="margin-top: .5em;">
                        <span class="fa fa-download"></span> {{ lang._("Download PNG") }}
                    </a>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._("Close") }}</button>
            </div>
        </div>
    </div>
</div>

<script>
//<![CDATA[
$(function () {
    var gridOpts = {
        "rowActions": function (column, row) {
            // Bootgrid row-action formatter: edit, export, revoke (red).
            // Revoke is the destructive delete + service-restart path (Task 11);
            // the standard 'deleteSelected' button is hidden in favour of it
            // so the operator gets the warning modal.
            var html = '<button type="button" class="btn btn-xs btn-default" data-action="edit" data-row-id="' + row.uuid + '"><span class="fa fa-pencil"></span></button> ';
            html    += '<button type="button" class="btn btn-xs btn-default tt-export" data-username="' + $.fn.bootgrid.escape(row.username) + '"><span class="fa fa-share-square-o"></span> ' + "{{ lang._('Export') }}" + '</button> ';
            html    += '<button type="button" class="btn btn-xs btn-danger tt-revoke" data-uuid="' + row.uuid + '" data-username="' + $.fn.bootgrid.escape(row.username) + '"><span class="fa fa-ban"></span> ' + "{{ lang._('Revoke') }}" + '</button>';
            return html;
        }
    };

    $("#grid-users").UIBootgrid({
        search: '/api/trusttunnel/server/searchUser/',
        get:    '/api/trusttunnel/server/getUser/',
        set:    '/api/trusttunnel/server/setUser/',
        add:    '/api/trusttunnel/server/addUser/',
        del:    '/api/trusttunnel/server/delUser/',
        options: gridOpts
    });

    // --- Export deeplink modal wiring ---
    function fetchAndShow(username) {
        ajaxCall('/api/trusttunnel/deeplink/export', {username: username}, function (data, status) {
            if (!data || data.status !== 'ok' || !data.uri) {
                BootstrapDialog.show({
                    title: "{{ lang._('Export failed') }}",
                    type: BootstrapDialog.TYPE_DANGER,
                    message: (data && data.error) ? data.error : "{{ lang._('Unknown error') }}"
                });
                return;
            }
            $('#DeeplinkUri').val(data.uri);
            if (data.qr_png_base64) {
                var src = 'data:image/png;base64,' + data.qr_png_base64;
                $('#DeeplinkQrImg').attr('src', src);
                $('#DeeplinkDownloadBtn').attr('href', src);
            }
        });
    }

    $(document).on('click', '.tt-export', function (e) {
        e.preventDefault();
        var username = $(this).data('username');
        $('#DeeplinkUserName').text(username);
        $('#DeeplinkUri').val('');
        $('#DeeplinkQrImg').attr('src', '');
        $('#DialogDeeplink').modal('show');
        fetchAndShow(username);
    });

    $('#DeeplinkCopyBtn').on('click', function (e) {
        e.preventDefault();
        var node = $('#DeeplinkUri')[0];
        node.select();
        try { document.execCommand('copy'); } catch (_) {}
        node.blur();
    });

    // --- Revoke flow (Task 11) ---
    $(document).on('click', '.tt-revoke', function (e) {
        e.preventDefault();
        var uuid     = $(this).data('uuid');
        var username = $(this).data('username');
        BootstrapDialog.confirm({
            title: "{{ lang._('Revoke user') }}: " + username,
            type:  BootstrapDialog.TYPE_DANGER,
            message: "{{ lang._('Are you sure you want to revoke this user? Any connected client using this account will be disconnected on next handshake.') }}",
            btnOKLabel: "{{ lang._('Revoke') }}",
            btnOKClass: 'btn-danger',
            callback: function (ok) {
                if (!ok) return;
                ajaxCall('/api/trusttunnel/server/delUser/' + uuid, {}, function (data) {
                    if (data && (data.result === 'deleted' || data.status === 'ok')) {
                        $('#grid-users').bootgrid('reload');
                    } else {
                        BootstrapDialog.show({
                            title: "{{ lang._('Revoke failed') }}",
                            type:  BootstrapDialog.TYPE_DANGER,
                            message: (data && data.error) ? data.error : "{{ lang._('Unknown error') }}"
                        });
                    }
                });
            }
        });
    });
});
//]]>
</script>
