/**
 * Drusoft Shipping for Sameday — checkout UI.
 *
 * Adds three delivery choices under the city field: to an address, to an
 * easybox, or to a SAMEDAY point. The two pickup options get a searchable
 * dropdown and the same Leaflet map the Speedy and Econt plugins use, so a
 * customer who has met one of our couriers recognises the others.
 *
 * Much smaller than the Speedy version this was forked from, because Sameday
 * addresses a shipment with a plain city name and a county we resolve on the
 * server. There is no province→city cascade, no street database and no service
 * picker to keep in sync.
 *
 * Living beside the siblings:
 *   - window.__drushfActiveCourier says which courier owns the checkout layout.
 *     Whoever takes over sets it; whoever leaves reads it and, if a sibling has
 *     taken over, removes only its own fields instead of restoring the stock
 *     checkout on top of the newcomer's work.
 *   - Our remembered city and point live in OUR cache, never read back from the
 *     shared address fields — those may already hold the sibling's values by
 *     the time we run.
 */
jQuery(function ($) {
    'use strict';

    if (typeof drushfs_params === 'undefined') {
        return;
    }

    var params = drushfs_params;
    var METHOD = params.method_id || 'drushfs_sameday';

    var isActive = false;
    var settingUp = false;
    var context = 'billing';

    // Our own memory. Never read these back from the shared checkout fields.
    var lastType = params.current_type || 'address';
    var lastPointId = params.current_office_id ? String(params.current_office_id) : '';
    var lastPointLabel = '';
    var lastCity = params.current_city || '';

    var allPointsCache = null;
    var allPointsPromise = null;
    var searchTimer = null;

    /* ------------------------------------------------------------------
     * Helpers
     * --------------------------------------------------------------- */

    function chosenMethod() {
        var $checked = $('input[name^="shipping_method"]:checked');
        if ($checked.length) {
            return String($checked.val() || '');
        }
        // A zone with a single method renders a hidden input instead of radios.
        var $single = $('input[name^="shipping_method"][type="hidden"]');
        return $single.length ? String($single.val() || '') : '';
    }

    function isSamedaySelected() {
        return chosenMethod().indexOf(METHOD) === 0;
    }

    function updateContext() {
        context = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';
    }

    function cityValue() {
        var city = $('#' + context + '_city').val();
        return city ? String(city).trim() : '';
    }

    function pointLabel(point) {
        return point.name + ', ' + point.city + ' — ' + point.address;
    }

    /* ------------------------------------------------------------------
     * The delivery-type block
     * --------------------------------------------------------------- */

    function renderDeliveryOptions() {
        if ($('#sameday-delivery-type-field').length) {
            return;
        }

        // Same markup as Speedy and Econt — input and label as siblings inside
        // a *-delivery-type-wrapper — so a store's styling for one courier's
        // radios (druoutlet's theme lays them out as a two-column grid with
        // 18 px accent radios) applies to all three. The first version nested
        // the input inside its label and got the browser's 13 px default.
        var radio = function (value, text, extraClass) {
            var id = 'sameday_delivery_type_' + value;
            var cls = extraClass ? ' class="' + extraClass + '"' : '';
            return '<input type="radio" name="sameday_delivery_type" id="' + id + '" value="' + value + '"' + cls + '>' +
                '<label for="' + id + '"' + cls + '>' + text + '</label>';
        };

        var html =
            '<p class="form-row form-row-wide" id="sameday-delivery-type-field">' +
            '<label>' + params.i18n.delivery_method + '</label>' +
            '<span class="woocommerce-input-wrapper" id="sameday-delivery-type-wrapper">' +
            radio('address', params.i18n.to_address) +
            radio('easybox', params.i18n.to_easybox) +
            radio('pudo', params.i18n.to_pudo, 'sameday-pudo-option') +
            '</span></p>' +
            '<p class="form-row form-row-wide" id="sameday-point-field" style="display:none;">' +
            '<label for="sameday_point_select" id="sameday-point-label">' + params.i18n.select_easybox + '</label>' +
            '<select id="sameday_point_select" class="sameday-point-select"></select>' +
            '<input type="hidden" name="sameday_office_id" id="sameday_office_id" value="">' +
            '</p>';

        $('#' + context + '_city_field').after(html);

        // A shop with no SAMEDAY point in the country should not offer one.
        if (!params.has_pudo_anywhere) {
            $('.sameday-pudo-option').hide();
        }

        $('input[name="sameday_delivery_type"]').on('change', function () {
            handleTypeChange($(this).val());
        });

        $('#sameday_point_select').on('change', function () {
            var id = $(this).val();
            var label = $(this).find('option:selected').text();
            setPoint(id, label);
        });

        // Restore what this customer chose before.
        $('input[name="sameday_delivery_type"][value="' + lastType + '"]').prop('checked', true);
        handleTypeChange(lastType, true);
    }

    function handleTypeChange(type, restoring) {
        lastType = type || 'address';

        var pickup = (lastType === 'easybox' || lastType === 'pudo');

        $('#sameday-point-field').toggle(pickup);
        $('#sameday-point-label').text(
            lastType === 'pudo' ? params.i18n.select_pudo : params.i18n.select_easybox
        );

        // Delivering to a locker means the street address is irrelevant; the
        // point's own address goes on the waybill instead.
        toggleAddressFields(!pickup, lastType);

        if (pickup) {
            loadPoints();
        } else {
            setPoint('', '');
        }

        placeMapButton();

        if (!restoring) {
            persist();
            refreshRates();
        }
    }

    // WooCommerce still requires the street when its field is hidden, so a
    // locker order was refused with "Адрес е задължително поле" (browser test,
    // 17.09.2026). Speedy and Econt put the delivery type into the hidden street
    // — "До офис" / "До автомат" — and so does this: the waybill goes to the
    // locker's own address, never to this field.
    function toggleAddressFields(show, type) {
        var labels = [params.i18n.to_easybox, params.i18n.to_pudo];
        var $address1 = $('#' + context + '_address_1');

        $.each(['address_1', 'address_2'], function (_, key) {
            var $field = $('#' + context + '_' + key + '_field');
            if ($field.length) {
                $field.toggle(!!show);
            }
        });

        if (show) {
            // Clear only our placeholder, never a street the customer typed.
            if (labels.indexOf($address1.val()) !== -1) {
                $address1.val('');
            }
            return;
        }

        $address1.val(type === 'pudo' ? params.i18n.to_pudo : params.i18n.to_easybox);
    }

    /* ------------------------------------------------------------------
     * Points: dropdown and map
     * --------------------------------------------------------------- */

    function loadPoints(term) {
        var $select = $('#sameday_point_select');
        if (!$select.length) {
            return;
        }

        $select.prop('disabled', true).html('<option>' + params.i18n.searching + '</option>');

        $.ajax({
            url: params.ajax_url,
            method: 'POST',
            data: {
                action: 'drushfs_find_points',
                nonce: params.nonce,
                term: term || '',
                delivery_type: lastType,
                city: cityValue()
            }
        }).done(function (response) {
            var points = (response && response.success && Array.isArray(response.data)) ? response.data : [];
            fillSelect(points);
        }).fail(function () {
            $select.prop('disabled', false).html('<option value="">' + params.i18n.no_results + '</option>');
        });
    }

    function fillSelect(points) {
        var $select = $('#sameday_point_select');
        var html = '<option value="">' +
            (lastType === 'pudo' ? params.i18n.select_pudo : params.i18n.select_easybox) +
            '</option>';

        if (!points.length) {
            html = '<option value="">' + params.i18n.no_results + '</option>';
        }

        $.each(points, function (_, p) {
            // A locker Sameday reports as filling up is still selectable, but
            // the customer deserves to know before they pick it.
            var label = p.text + (p.busy ? ' ⚠' : '');
            html += '<option value="' + p.id + '">' + $('<div>').text(label).html() + '</option>';
        });

        $select.html(html).prop('disabled', false);

        if (lastPointId) {
            $select.val(lastPointId);
            // The remembered point may not be in this city's list; keep the id
            // rather than silently dropping the customer's choice.
            if (!$select.val()) {
                $select.append(
                    '<option value="' + lastPointId + '" selected>' +
                    $('<div>').text(lastPointLabel || lastPointId).html() + '</option>'
                );
            }
        }

        if (typeof $select.select2 === 'function') {
            $select.select2({
                width: '100%',
                placeholder: lastType === 'pudo' ? params.i18n.select_pudo : params.i18n.select_easybox
            });
        }
    }

    function setPoint(id, label) {
        lastPointId = id ? String(id) : '';
        lastPointLabel = label || '';
        $('#sameday_office_id').val(lastPointId);

        if (lastPointId) {
            persist();
            refreshRates();
        }
    }

    function fetchAllPoints() {
        if (allPointsCache) {
            return $.Deferred().resolve(allPointsCache).promise();
        }
        if (allPointsPromise) {
            return allPointsPromise;
        }

        allPointsPromise = $.ajax({
            url: params.ajax_url,
            method: 'POST',
            data: { action: 'drushfs_get_all_points', nonce: params.nonce }
        }).then(function (response) {
            if (response && response.success && Array.isArray(response.data)) {
                allPointsCache = response.data;
            }
            allPointsPromise = null;
            return allPointsCache || [];
        }, function () {
            allPointsPromise = null;
            return [];
        });

        return allPointsPromise;
    }

    // Idempotent: called from every setup path so the button follows the
    // layout as our fields appear and disappear.
    function placeMapButton() {
        $('#sameday-map-button-wrapper').remove();

        if (!isActive || lastType === 'address') {
            return;
        }

        var html =
            '<p class="form-row form-row-wide" id="sameday-map-button-wrapper" style="margin-top:10px;">' +
            '<button type="button" id="sameday-open-map" class="button" style="width:100%;">' +
            params.i18n.select_from_map + '</button></p>';

        $('#sameday-point-field').after(html);

        $('#sameday-open-map').off('click.samedayMap').on('click.samedayMap', openMap);
    }

    function openMap() {
        if (!window.DrushfsMap) {
            return;
        }

        // map.js speaks Speedy's vocabulary: 'automat' is the unstaffed point
        // (our easybox), 'office' the staffed one (SAMEDAY point).
        var filter = (lastType === 'pudo') ? 'office' : 'automat';
        var title = (lastType === 'pudo') ? params.i18n.map_title_pudo : params.i18n.map_title_easybox;

        fetchAllPoints().then(function (all) {
            // Open zoomed to the customer's city, as the Speedy and Econt maps
            // do. Our city field holds a name, not an id, so borrow the id from
            // any point in that city.
            var city = cityValue().toLowerCase();
            var inCity = city ? all.find(function (p) {
                return String(p.city_name || '').toLowerCase() === city;
            }) : null;

            window.DrushfsMap.open(all, handleMapPick, {
                title: title,
                hint: params.i18n.map_hint,
                pickLabel: params.i18n.map_pick,
                errorLabel: params.i18n.map_error,
                defaultFilter: filter,
                focusCityId: inCity ? inCity.city_id : null,
                i18n: {
                    offices: params.i18n.map_filter_office,
                    automats: params.i18n.map_filter_automat,
                    both: params.i18n.map_filter_both,
                    search_placeholder: params.i18n.map_search_placeholder,
                    results_count: params.i18n.map_results_count,
                    search_no_results: params.i18n.map_search_no_results
                }
            });
        });
    }

    function handleMapPick(point) {
        // Picking on the map may mean switching type: someone browsing easybox
        // can land on a SAMEDAY point and vice versa.
        var mapType = (point.office_type === 'PUDO') ? 'pudo' : 'easybox';

        if (mapType !== lastType) {
            $('input[name="sameday_delivery_type"][value="' + mapType + '"]').prop('checked', true);
            handleTypeChange(mapType, true);
        }

        var label = pointLabel({
            name: point.name,
            city: point.city_name,
            address: point.address
        });

        var $select = $('#sameday_point_select');
        if ($select.length && !$select.find('option[value="' + point.id + '"]').length) {
            $select.append('<option value="' + point.id + '">' + $('<div>').text(label).html() + '</option>');
        }
        $select.val(String(point.id));
        if (typeof $select.select2 === 'function') {
            $select.trigger('change.select2');
        }

        setPoint(point.id, label);
    }

    /* ------------------------------------------------------------------
     * Server state
     * --------------------------------------------------------------- */

    function persist() {
        lastCity = cityValue();

        try {
            sessionStorage.setItem('sameday_delivery_type', lastType);
            sessionStorage.setItem('sameday_office_id', lastPointId);
        } catch (e) {
            // Private browsing: the server session below still carries it.
        }

        $.ajax({
            url: params.ajax_url,
            method: 'POST',
            data: {
                action: 'drushfs_save_cart_selection',
                nonce: params.nonce,
                delivery_type: lastType,
                office_id: lastPointId,
                city: lastCity
            }
        });
    }

    function refreshRates() {
        $(document.body).trigger('update_checkout');
    }

    /* ------------------------------------------------------------------
     * Activate / deactivate
     * --------------------------------------------------------------- */

    // The stock checkout order puts the street, then the city, then the region.
    // Speedy and Econt move region and city up under the country, and a customer
    // switching between couriers should not watch the fields jump around, so
    // Sameday does the same. The original priorities are kept to put them back.
    var originalPriorities = {};

    function reorderFields() {
        var $country = $('#' + context + '_country_field');
        var $state = $('#' + context + '_state_field');
        var $city = $('#' + context + '_city_field');

        if (!originalPriorities[context]) {
            originalPriorities[context] = {
                state: $state.attr('data-priority'),
                city: $city.attr('data-priority')
            };
        }

        $state.insertAfter($country).attr('data-priority', 41);
        $city.insertAfter($state).attr('data-priority', 42);
        $('#sameday-delivery-type-field').insertAfter($city);
        $('#sameday-point-field').insertAfter('#sameday-delivery-type-field');
    }

    function restoreFieldOrder() {
        var $country = $('#' + context + '_country_field');
        var $state = $('#' + context + '_state_field');
        var $city = $('#' + context + '_city_field');
        var $address1 = $('#' + context + '_address_1_field');
        var $address2 = $('#' + context + '_address_2_field');
        var prio = originalPriorities[context] || {};

        $address1.insertAfter($country);
        $address2.insertAfter($address1);
        $city.insertAfter($address2.length ? $address2 : $address1);
        if (prio.city) {
            $city.attr('data-priority', prio.city);
        }
        $state.insertAfter($city);
        if (prio.state) {
            $state.attr('data-priority', prio.state);
        }
    }

    function setup() {
        if (settingUp) {
            return;
        }
        settingUp = true;
        isActive = true;

        window.__drushfActiveCourier = 'sameday';

        updateContext();
        renderDeliveryOptions();
        reorderFields();
        placeMapButton();

        settingUp = false;
    }

    function teardown() {
        if (!isActive) {
            return;
        }
        isActive = false;

        // A sibling courier that has just taken over has already laid the
        // fields out for itself. Tear down only what is ours.
        $('#sameday-delivery-type-field, #sameday-point-field, #sameday-map-button-wrapper').remove();

        toggleAddressFields(true);

        // Only put the stock order back when the customer left for a
        // non-courier method. If Speedy or Econt took over, they have already
        // laid the fields out for themselves.
        if (window.__drushfActiveCourier === 'sameday') {
            window.__drushfActiveCourier = '';
            restoreFieldOrder();
        }
    }

    /* ------------------------------------------------------------------
     * Wiring
     * --------------------------------------------------------------- */

    // Speedy and Econt (up to their September 2026 releases) recognise only each
    // other: when they hand the checkout over they restore the stock field order
    // unless the newcomer is their known sibling — so switching from Speedy to
    // Sameday put region and city back below the street, on top of our layout.
    // Their teardown runs in the same event as our setup, so reapply the order
    // once it has finished.
    function setupAndSettle() {
        setup();
        setTimeout(function () {
            if (isActive) {
                reorderFields();
            }
        }, 60);
    }

    $(document.body).on('updated_checkout', function () {
        updateContext();

        if (isSamedaySelected()) {
            setupAndSettle();
        } else {
            teardown();
        }
    });

    $(document.body).on('change', 'input[name^="shipping_method"]', function () {
        if (isSamedaySelected()) {
            setupAndSettle();
        } else {
            teardown();
        }
    });

    // Changing the city changes which points are near enough to matter.
    $(document.body).on('change', '#billing_city, #shipping_city', function () {
        if (!isActive || lastType === 'address') {
            return;
        }
        if (cityValue() === lastCity) {
            return;
        }
        allPointsCache = null;
        loadPoints();
    });

    // Typing in the select2 search box filters against the whole country.
    $(document).on('input', '.select2-search__field', function () {
        if (!isActive || lastType === 'address') {
            return;
        }
        var term = $(this).val();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            loadPoints(term);
        }, 300);
    });

    // A locker order must carry a locker.
    $('form.checkout').on('checkout_place_order', function () {
        if (!isActive || lastType === 'address') {
            return true;
        }
        if (!lastPointId) {
            window.alert(params.i18n.alert_select_point);
            return false;
        }
        return true;
    });
});
