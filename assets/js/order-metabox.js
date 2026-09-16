jQuery(document).ready(function($) {

    var params = drushfs_metabox_params;

    function showMetaboxNotice(message, type) {
        var $notice = $('#speedy-metabox-notice');
        var color = (type === 'error') ? '#a00' : '#00a32a';
        $notice.html('<p style="color: ' + color + ';">' + message + '</p>');
        setTimeout(function() { $notice.fadeOut(400, function() { $(this).html('').show(); }); }, 5000);
    }

    // Generate Waybill
    $(document).on('click', '.speedy-order-generate', function(e) {
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
                    // Reload to show the waybill info
                    location.reload();
                } else {
                    showMetaboxNotice(response.data, 'error');
                    button.text('Generate Waybill').prop('disabled', false);
                }
            }
        });
    });

    // Request Courier
    $(document).on('click', '.speedy-order-request-courier', function(e) {
        e.preventDefault();
        var button = $(this);
        var orderId = button.data('order-id');

        button.text(params.i18n.requesting).prop('disabled', true);

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfs_request_courier',
                order_id: orderId,
                nonce: params.nonce
            },
            success: function(response) {
                if (response.success) {
                    button.replaceWith(
                        '<span class="button disabled" style="text-align:center; color: green;">' +
                        params.i18n.courier_requested + '</span>'
                    );
                    showMetaboxNotice(response.data, 'success');
                } else {
                    showMetaboxNotice(response.data, 'error');
                    button.text('Request Courier').prop('disabled', false);
                }
            }
        });
    });

    // Cancel Shipment
    $(document).on('click', '.speedy-order-cancel', function(e) {
        e.preventDefault();
        if (!confirm(params.i18n.confirm_cancel)) {
            return;
        }

        var button = $(this);
        var orderId = button.data('order-id');
        var $content = $('#speedy-metabox-content');

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
                    // Replace metabox content with Generate button
                    $content.html(
                        '<p>No waybill generated yet.</p>' +
                        '<button type="button" class="button button-primary speedy-order-generate" data-order-id="' + orderId + '">' +
                        'Generate Waybill</button>'
                    );
                    showMetaboxNotice(response.data, 'success');
                } else {
                    showMetaboxNotice(response.data, 'error');
                    button.text('Cancel Shipment').prop('disabled', false);
                }
            }
        });
    });
});

