jQuery(document).ready(function($) {

    var params = drushfs_metabox_params;

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function showMetaboxNotice(message, type) {
        var $notice = $('#sameday-metabox-notice');
        var color = (type === 'error') ? '#a00' : '#00a32a';
        $notice.html('<p style="color: ' + color + ';">' + escapeHtml(message) + '</p>');
        setTimeout(function() { $notice.fadeOut(400, function() { $(this).html('').show(); }); }, 5000);
    }

    // Create the waybill.
    $(document).on('click', '.sameday-order-generate', function(e) {
        e.preventDefault();
        var button = $(this);
        var orderId = button.data('order-id');

        button.text(params.i18n.generating).prop('disabled', true);

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfs_generate_waybill',
                order_id: orderId,
                nonce: params.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Reload so the waybill number, label and cancel button appear.
                    location.reload();
                } else {
                    showMetaboxNotice(response.data, 'error');
                    button.text(params.i18n.generate).prop('disabled', false);
                }
            },
            error: function() {
                showMetaboxNotice(params.i18n.request_failed, 'error');
                button.text(params.i18n.generate).prop('disabled', false);
            }
        });
    });

    // Cancel the waybill.
    //
    // There is no "request a courier" button here, unlike the Speedy plugin
    // this was forked from: Sameday's client API has no courier-request
    // endpoint, so such a button could never do anything.
    $(document).on('click', '.sameday-order-cancel', function(e) {
        e.preventDefault();
        if (!confirm(params.i18n.confirm_cancel)) {
            return;
        }

        var button = $(this);
        var orderId = button.data('order-id');
        var $content = $('#sameday-metabox-content');

        button.text(params.i18n.cancelling).prop('disabled', true);

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfs_cancel_shipment',
                order_id: orderId,
                nonce: params.nonce
            },
            success: function(response) {
                if (response.success) {
                    $content.html(
                        '<p>' + params.i18n.no_waybill + '</p>' +
                        '<button type="button" class="button button-primary sameday-order-generate" data-order-id="' + orderId + '">' +
                        params.i18n.generate + '</button>'
                    );
                    showMetaboxNotice(response.data, 'success');
                } else {
                    showMetaboxNotice(response.data, 'error');
                    button.text(params.i18n.cancel).prop('disabled', false);
                }
            },
            error: function() {
                showMetaboxNotice(params.i18n.request_failed, 'error');
                button.text(params.i18n.cancel).prop('disabled', false);
            }
        });
    });
});
