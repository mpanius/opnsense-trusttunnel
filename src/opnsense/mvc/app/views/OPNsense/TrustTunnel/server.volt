{#
 # Copyright (c) 2026 Mikhail Panyushkin <mpanius@gmail.com>
 # BSD-2-Clause — see LICENSE.
 # Server tab — bootgrid for users + per-user Export Deeplink modal (QR).
 # Server settings form, self-signed cert generation modal, firewall
 # rule status will be wired in Tasks 9-11.
 #}

{{ partial("layout_partials/base_form",['fields':serverForm,'id':'frm_ServerSettings']) }}

<section class="page-content-main">
    <div class="container-fluid">
        <div class="row">
            <section class="col-xs-12">
                <hr/>
                <button class="btn btn-primary" id="btnApplyServer" type="button">
                    <b>{{ lang._('Apply') }}</b>
                    <i id="btnApplyServerProgress"></i>
                </button>
                <button class="btn btn-default" id="btnGenSelfSigned" type="button">
                    <span class="fa fa-certificate"></span> {{ lang._('Generate self-signed cert') }}
                </button>
                <br/><br/>
            </section>
        </div>
    </div>
</section>

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

{# --- Add/Edit User modal (driven by base_dialog from userForm) --- #}
{{ partial("layout_partials/base_dialog",['fields':userForm,'id':'DialogUser','label':lang._('User')]) }}

{# --- Generate self-signed cert modal --- #}
<div id="DialogGenCert" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">{{ lang._('Generate self-signed certificate') }}</h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>{{ lang._('Common Name') }}</label>
                    <input type="text" class="form-control" id="GenCN" placeholder="vpn.example.com">
                </div>
                <div class="form-group">
                    <label>{{ lang._('Validity (days)') }}</label>
                    <input type="number" class="form-control" id="GenDays" value="365" min="1" max="3650">
                </div>
                <div class="form-group">
                    <label>{{ lang._('Subject Alternative Names (comma-separated)') }}</label>
                    <input type="text" class="form-control" id="GenSans" placeholder="vpn.example.com,alt.example.com">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-default" data-dismiss="modal">{{ lang._('Cancel') }}</button>
                <button class="btn btn-primary" id="GenSubmit">{{ lang._('Generate') }}</button>
            </div>
        </div>
    </div>
</div>

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

    // Map model field paths to dialog input IDs (OPNsense bootgrid convention).
    mapDataToFormUI({'frm_ServerSettings': '/api/trusttunnel/server/get'}).done(function() {
        // Trigger checkbox/dropdown styling after data binding.
        formatTokenizersUI();
        $('.selectpicker').selectpicker('refresh');
    });

    $("#grid-users").UIBootgrid({
        search: '/api/trusttunnel/server/searchUser/',
        get:    '/api/trusttunnel/server/getUser/',
        set:    '/api/trusttunnel/server/setUser/',
        add:    '/api/trusttunnel/server/addUser/',
        del:    '/api/trusttunnel/server/delUser/',
        options: gridOpts
    });

    // Apply button: save server settings, then trigger reconfigure.
    $('#btnApplyServer').on('click', function () {
        $('#btnApplyServerProgress').addClass('fa fa-spinner fa-pulse');
        saveFormToEndpoint('/api/trusttunnel/server/set', 'frm_ServerSettings', function () {
            ajaxCall('/api/trusttunnel/server/reconfigure', {}, function (data, status) {
                $('#btnApplyServerProgress').removeClass('fa fa-spinner fa-pulse');
                BootstrapDialog.show({
                    title: "{{ lang._('Apply result') }}",
                    type:  (data && data.status === 'ok') ? BootstrapDialog.TYPE_SUCCESS : BootstrapDialog.TYPE_DANGER,
                    message: (data && data.output) ? data.output : ((data && data.error) || "{{ lang._('Unknown') }}")
                });
            });
        });
    });

    // Generate self-signed cert flow.
    $('#btnGenSelfSigned').on('click', function () {
        $('#GenCN').val($('#row_trusttunnel\\.server\\.hostname').val() || 'vpn.example.com');
        $('#GenDays').val('365');
        $('#GenSans').val('');
        $('#DialogGenCert').modal('show');
    });
    $('#GenSubmit').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        ajaxCall('/api/trusttunnel/server/generateSelfSigned', {
            common_name: $('#GenCN').val(),
            days:        $('#GenDays').val(),
            sans:        $('#GenSans').val()
        }, function (data, status) {
            $btn.prop('disabled', false);
            if (data && data.status === 'ok') {
                $('#DialogGenCert').modal('hide');
                BootstrapDialog.show({
                    title: "{{ lang._('Certificate generated') }}",
                    type:  BootstrapDialog.TYPE_SUCCESS,
                    message: "{{ lang._('Cert created (refid:') }} " + data.refid + "). " + "{{ lang._('Refresh the cert dropdown to select it.') }}"
                });
                // Re-load form to pick up the new cert in the dropdown.
                mapDataToFormUI({'frm_ServerSettings': '/api/trusttunnel/server/get'});
            } else {
                var msg = (data && data.errors) ? data.errors.join('; ')
                        : ((data && data.error) || "{{ lang._('Unknown error') }}");
                BootstrapDialog.show({
                    title: "{{ lang._('Generation failed') }}",
                    type:  BootstrapDialog.TYPE_DANGER,
                    message: msg
                });
            }
        });
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
