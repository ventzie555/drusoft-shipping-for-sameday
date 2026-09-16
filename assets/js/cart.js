/**
 * Drusoft Shipping for Sameday — cart page.
 *
 * The cart shows the three delivery choices so the customer sees the right
 * price before checkout. Choosing the actual easybox or SAMEDAY point happens
 * at checkout, where the address fields live — here we only need the type,
 * because the price differs between a door and a locker.
 *
 * Far smaller than the Speedy version this was forked from: Sameday needs no
 * province, no city id and no postcode to quote, so the shipping calculator is
 * left exactly as WooCommerce built it.
 */
(function ($, params) {
    'use strict';

    if (!params) {
        return;
    }

    var isActive = false;
    var isUpdating = false;

    function selectedMethod() {
        return $('input[name^="shipping_method"][type="radio"]:checked, input[name^="shipping_method"][type="hidden"]').first();
    }

    function isSamedaySelected() {
        var val = selectedMethod().val();
        return !!val && String(val).indexOf(params.method_id) === 0;
    }

    /**
     * Which delivery types this shop can actually offer right now.
     *
     * Read from the span the server prints next to the rate after every cart
     * update, falling back to what was localised on page load. A type with no
     * points in the customer's city is hidden rather than offered and then
     * refused at checkout.
     */
    function availability() {
        var $data = $('#sameday-availability-data');

        if ($data.length) {
            return {
                easybox: $data.data('has-easybox') === 1 || $data.data('has-easybox') === '1',
                pudo: $data.data('has-pudo') === 1 || $data.data('has-pudo') === '1'
            };
        }

        return {
            easybox: !!params.has_easybox,
            pudo: !!params.has_pudo
        };
    }

    function render($container) {
        if ($('#sameday-cart-selector').length) {
            $('input[name="sameday_cart_type"][value="' + (params.current_type || 'address') + '"]')
                .prop('checked', true);
            applyAvailability();
            return;
        }

        var html =
            '<div id="sameday-cart-selector">' +
            '<p class="sameday-cart-heading">' + params.i18n.delivery_method + '</p>' +
            '<div id="sameday-cart-options">' +
            option('address', params.i18n.to_address) +
            option('easybox', params.i18n.to_easybox) +
            option('pudo', params.i18n.to_pudo) +
            '</div></div>';

        $container.append(html);

        $('input[name="sameday_cart_type"][value="' + (params.current_type || 'address') + '"]')
            .prop('checked', true);

        applyAvailability();

        $(document)
            .off('change.samedayCart', 'input[name="sameday_cart_type"]')
            .on('change.samedayCart', 'input[name="sameday_cart_type"]', onTypeChange);
    }

    function option(value, label) {
        return '<div class="sameday-cart-option" data-type="' + value + '">' +
            '<label><input type="radio" name="sameday_cart_type" value="' + value + '"> ' +
            '<span>' + label + '</span></label></div>';
    }

    function applyAvailability() {
        var have = availability();

        $('.sameday-cart-option[data-type="easybox"]').toggle(have.easybox);
        $('.sameday-cart-option[data-type="pudo"]').toggle(have.pudo);

        // If the remembered choice is no longer possible, fall back to the door
        // rather than leaving a hidden radio selected.
        var current = $('input[name="sameday_cart_type"]:checked').val();
        if ((current === 'easybox' && !have.easybox) || (current === 'pudo' && !have.pudo)) {
            $('input[name="sameday_cart_type"][value="address"]').prop('checked', true);
            params.current_type = 'address';
        }
    }

    function onTypeChange() {
        if (isUpdating) {
            return;
        }

        var type = $(this).val();
        params.current_type = type;

        $('#sameday_cart_delivery_type').val(type);

        isUpdating = true;
        $('#sameday-cart-selector').addClass('sameday-updating');

        $.ajax({
            url: params.ajax_url,
            method: 'POST',
            data: {
                action: 'drushfs_update_cart_selection',
                nonce: params.nonce,
                delivery_type: type,
                city: $('#calc_shipping_city').val() || params.current_city || ''
            }
        }).always(function () {
            // Let WooCommerce redraw the totals; our selector is re-rendered
            // by the updated_cart_totals handler below.
            $('[name="update_cart"]').prop('disabled', false).trigger('click');
        });
    }

    function attach() {
        var $method = selectedMethod();
        isActive = isSamedaySelected();

        if (!isActive) {
            $('#sameday-cart-selector').remove();
            return;
        }

        render($method.closest('li'));
    }

    $(function () {
        attach();

        $(document.body).on('updated_cart_totals', function () {
            isUpdating = false;
            $('#sameday-cart-selector').removeClass('sameday-updating');
            attach();
        });

        $(document).on('change', 'input[name^="shipping_method"]', function () {
            attach();
        });
    });

})(jQuery, window.drushfs_params);
