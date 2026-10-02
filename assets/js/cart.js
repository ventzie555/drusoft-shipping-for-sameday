/**
 * Sameday on the cart page: the same region and city list in the shipping
 * calculator, and the same delivery-type chooser, as the Speedy and Econt
 * siblings.
 */
(function ($, params) {
    'use strict';

    let isSamedayActive = false;
    let originalCityHtml = null;
    let $cityField = null;
    let $postcodeField = null;
    let $stateField = null;

    // Deduplication trackers — survive across updated_cart_totals so we don't
    // re-fetch cities or re-check availability when nothing actually changed.
    let lastStateProcessed = null;
    let lastCityProcessed = null;

    // Guard flag: true while we are waiting for a cart update that WE triggered.
    // Prevents the updated_cart_totals handler from cascading into another cycle.
    let cartUpdatePending = false;

    // Guard flag: true while an AJAX session-update + cart-update sequence is
    // in progress.  Prevents double-clicks / rapid changes from stacking up.
    let isUpdating = false;

    $(document).ready(function () {
        initCartElements();
        handleSamedayCart();

        $(document.body).on('updated_cart_totals', function () {
            // DOM elements are replaced after a cart update — re-grab them.
            initCartElements();

            if (cartUpdatePending) {
                // This update was triggered by us (city change, type change).
                // The session already has the correct data; we just need to
                // re-render the Sameday UI on the new DOM — NOT fetch cities or
                // trigger another cart update.
                cartUpdatePending = false;
                isUpdating = false;
                restoreSamedayUI();
                return;
            }

            // Genuinely external cart update (quantity change, coupon, etc.).
            // Re-render our UI. Don't reset dedup trackers — state/city haven't
            // changed, so we don't need to re-fetch.
            restoreSamedayUI();
        });

        // Listen for standard WooCommerce state changes in the calculator
        $(document).on('change', 'select#calc_shipping_state', function () {
            if (isSamedayActive) {
                const state = $(this).val();
                params.current_state = state;
                // State genuinely changed by the user — reset city tracker
                lastCityProcessed = null;
                // Clear postcode — it belongs to the previous city
                $postcodeField = $('#calc_shipping_postcode');
                $postcodeField.val('');
                // Persist state to WC session immediately
                saveSelectionToSession();
                handleCalculatorStateChange(state);
            }
        });

        // Listen for shipping method changes — use mousedown in CAPTURE phase
        // so our DOM cleanup runs before any other plugin's event handlers.
        document.addEventListener('mousedown', function(e) {
            const radio = e.target.closest('input[name^="shipping_method"]');
            if (!radio) return;

            const isSameday = radio.value && radio.value.indexOf(params.method_id) === 0;
            if (!isSameday && isSamedayActive) {
                isSamedayActive = false;
                $('#sameday-cart-selector').remove();
                resetCalculatorUI();
            }
        }, true);

        // Listen for shipping method radio changes — activation (switching TO Sameday)
        $(document).on('change', 'input[name^="shipping_method"]', function () {
            const $selected = $(this);
            const isSameday = $selected.val() && $selected.val().indexOf(params.method_id) === 0;

            if (isSameday && !isSamedayActive) {
                isSamedayActive = true;
                initCartElements();

                renderSamedaySelector($selected.closest('li'));
                initStateSelect2();
                $postcodeField = $('#calc_shipping_postcode');
                $postcodeField.prop('readonly', true).css('background-color', '#eee');
                $('button[name="calc_shipping"]').hide();

                // Open the calculator form so the user can pick a state/city
                const $calcForm = $('.shipping-calculator-form');
                if ($calcForm.length && $calcForm.is(':hidden')) {
                    $calcForm.show();
                }

                // If a state is already selected, load cities
                const state = $stateField.val() || params.current_state;
                if (state) {
                    handleCalculatorStateChange(state);
                }
            }
        });
    });

    /* ─── DOM helpers ─────────────────────────────────────── */

    function initCartElements() {
        $cityField = $('#calc_shipping_city');
        $postcodeField = $('#calc_shipping_postcode');
        $stateField = $('#calc_shipping_state');

        // Save the original city input HTML once (before we replace it)
        if ($cityField.length && originalCityHtml === null && $cityField.is('input')) {
            originalCityHtml = $cityField.parent().html();
        }
    }

    /**
     * After updated_cart_totals the entire shipping HTML is rebuilt by WC.
     * Re-render the Sameday selector and, if we already loaded cities for
     * the current state, rebuild the city dropdown without a new AJAX call.
     */
    function getSelectedShippingMethod() {
        return $('input[name^="shipping_method"][type="radio"]:checked, input[name^="shipping_method"][type="hidden"]').first();
    }

    function restoreSamedayUI() {
        const $selectedMethod = getSelectedShippingMethod();
        const isSameday = $selectedMethod.val() && $selectedMethod.val().indexOf(params.method_id) === 0;

        isSamedayActive = isSameday;

        if (!isSameday) {
            $('#sameday-cart-selector').remove();
            resetCalculatorUI();
            return;
        }

        renderSamedaySelector($selectedMethod.closest('li'));

        // Re-init state as searchable select2 with transliteration
        initStateSelect2();

        $postcodeField = $('#calc_shipping_postcode');
        $postcodeField.prop('readonly', true).css('background-color', '#eee');

        // Hide the calculator "Update" button — updates are automatic
        $('button[name="calc_shipping"]').hide();

        // Keep the calculator form open when a city is already chosen
        if (params.current_city_id || params.current_state) {
            const $calcForm = $('.shipping-calculator-form');
            if ($calcForm.length && $calcForm.is(':hidden')) {
                $calcForm.show();
            }
        }

        // Read availability from the server-rendered hidden element
        const $avail = $('#sameday-availability-data');
        if ($avail.length) {
            cachedHasEasybox = $avail.data('has-easybox') === 1;
            cachedHasPudo = $avail.data('has-pudo') === 1;
        }

        // Re-apply availability so easybox / SAMEDAY point radios are visible,
        // or hide the entire selector when only address is available.
        updateRadioVisibilityUI(cachedHasEasybox, cachedHasPudo);

        // If we already fetched cities, rebuild the dropdown from cache
        if (cachedCities && lastStateProcessed) {
            replaceCalculatorCityWithSelect(cachedCities);
        }

        // A sibling courier's handler for this same event may run after ours
        // and put the stock city input back; check again over the next moments.
        ensureCitySelect();
    }

    /**
     * Re-apply our city dropdown if it has been reverted to the stock <input>.
     * Speedy (to 1.1.3) resets the calculator city whenever it is not the chosen
     * courier, without checking whose select it is, and its handler runs after
     * ours. The Econt sibling defends itself the same way.
     */
    function ensureCitySelect(retries) {
        if (typeof retries !== 'number') retries = 5;
        if (!isSamedayActive) return;

        // The same sibling reset brings the calculator's "Update" button back
        // and unlocks the postcode; both are automatic while we are active.
        $('button[name="calc_shipping"]').hide();
        $('#calc_shipping_postcode').prop('readonly', true).css('background-color', '#eee');

        // ...and swaps our region list, which finds "София" when the customer
        // types "sof", for WooCommerce's plain one.
        const $state = $('select#calc_shipping_state');
        const inst = $state.length ? $state.data('select2') : null;
        if ($state.length && !(inst && inst.options && inst.options.options && inst.options.options.matcher === modelMatcher)) {
            SamedayModern.initStateSelect2($state, $state.val());
        }

        const $city = $('#calc_shipping_city');
        if (cachedCities && lastStateProcessed && $city.length && !($city.is('select') && $city.hasClass('sameday-city-select'))) {
            replaceCalculatorCityWithSelect(cachedCities);
        }
        if (retries > 0) setTimeout(function () { ensureCitySelect(retries - 1); }, 100);
    }

    /* ─── Main entry on first load ────────────────────────── */

    function handleSamedayCart() {
        const $selectedMethod = getSelectedShippingMethod();
        const isSameday = $selectedMethod.val() && $selectedMethod.val().indexOf(params.method_id) === 0;

        isSamedayActive = isSameday;

        if (isSameday) {
            // WC core cart script hides the calculator on ready.
            // Defer our .show() so it runs after WC initialization.
            setTimeout(function () {
                $('.shipping-calculator-form').show();
            }, 0);
            renderSamedaySelector($selectedMethod.closest('li'));

            // Init state as searchable select2 with transliteration
            initStateSelect2();
            $postcodeField.prop('readonly', true).css('background-color', '#eee');

            // Hide the calculator "Update" button — updates are automatic
            $('button[name="calc_shipping"]').hide();

            // If a city is already chosen, keep the calculator form open
            if (params.current_city_id || params.current_state) {
                const $calcForm = $('.shipping-calculator-form');
                if ($calcForm.length && $calcForm.is(':hidden')) {
                    $calcForm.show();
                }
            }

            // Use server-rendered availability data (no AJAX needed)
            if (params.current_city_id) {
                cachedHasEasybox = !!params.has_easybox;
                cachedHasPudo = !!params.has_pudo;
                updateRadioVisibilityUI(cachedHasEasybox, cachedHasPudo);
            }

            // Load cities for the state
            const state = $stateField.val() || params.current_state;
            if (state) {
                handleCalculatorStateChange(state);
            }
            ensureCitySelect();
        } else {
            $('#sameday-cart-selector').remove();
            resetCalculatorUI();
        }
    }

    /* ─── Persist selections to WC session (for checkout page) ── */

    /**
     * Save current Sameday selections to the WC session via AJAX.
     * This ensures the checkout page can read them even if the user
     * navigates to checkout without clicking the cart "Update" button.
     */
    function saveSelectionToSession() {
        const state = $stateField ? $stateField.val() : (params.current_state || '');
        const cityId = params.current_city_id || '';
        const deliveryType = params.current_type || 'address';
        const officeId = 0; // office is only relevant in checkout

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfs_save_cart_selection',
                nonce: params.nonce,
                state: state,
                city_id: cityId,
                delivery_type: deliveryType,
                office_id: officeId
            }
            // Fire-and-forget — no need to handle response
        });
    }

    /* ─── State → cities ──────────────────────────────────── */

    // Keep a cache of the last-fetched cities so we can rebuild the dropdown
    // after updated_cart_totals without a new AJAX call.
    let cachedCities = null;

    // Cache the last availability result so we can re-apply radio visibility
    // after the DOM is rebuilt by updated_cart_totals.
    let cachedHasEasybox = false;
    let cachedHasPudo = false;

    function handleCalculatorStateChange(stateCode) {
        if (!stateCode || stateCode === lastStateProcessed) return;
        lastStateProcessed = stateCode;
        cachedCities = null; // new state — invalidate city cache

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfs_get_cities',
                nonce: params.nonce,
                region: stateCode
            },
            success: function (response) {
                if (response.success) {
                    cachedCities = response.data;
                    replaceCalculatorCityWithSelect(response.data);
                    ensureCitySelect();
                }
            }
        });
    }

    /* ─── City dropdown ───────────────────────────────────── */

    function smartCityMatch(cityIdToSelect, city) {
        const id = String(city.id);
        const target = String(cityIdToSelect).toUpperCase();
        const cityName = city.name.toUpperCase();

        if (id === target) return true;
        if (cityName === target) return true;
        // A city carried over from Speedy or Econt arrives as "гр. Хасково";
        // Sameday's names carry no such prefix.
        return cityName === target.replace(/^(ГР\.|С\.)\s*/i, '');
    }

    /**
     * Build and insert the city <select> dropdown from the given city list.
     */
    function replaceCalculatorCityWithSelect(cities) {
        $cityField = $('#calc_shipping_city');
        // Only our own remembered city. The text WooCommerce pre-fills here may
        // be a Speedy or Econt city: selecting it by name would show a city with
        // no price behind it, because our session would still hold none.
        const cityIdToSelect = (params.current_city_id && String(params.current_city_id) !== '0') ? params.current_city_id : '';

        let options = `<option value="">${params.i18n.select_city || 'Select city'}</option>`;

        $.each(cities, function (index, city) {
            let selected = '';
            if (cityIdToSelect && smartCityMatch(cityIdToSelect, city)) {
                selected = 'selected';
                params.current_city_id = city.id;
            }
            const esc = SamedayModern.esc;
            options += `<option value="${esc(city.id)}" data-postcode="${esc(city.postcode || '')}" ${selected}>${esc(city.name)} ${city.postcode ? '(' + esc(city.postcode) + ')' : ''}</option>`;
        });

        const $wrapper = $cityField.parent();
        if ($wrapper.find('select').length) {
            try { $wrapper.find('select').select2('destroy'); } catch (e) { /* ok */ }
        }
        $wrapper.html(`<select name="calc_shipping_city" id="calc_shipping_city" class="sameday-city-select">${options}</select>`);

        $cityField = $('#calc_shipping_city');

        $cityField.select2({
            width: '100%',
            matcher: modelMatcher
        });

        $cityField.on('change', function () {
            handleCityChange($(this).val());
        });

        // If a city is already selected, set the postcode.
        // Availability is handled by server-rendered data, not a separate call.
        const selectedVal = $cityField.val();
        if (selectedVal) {
            const $selected = $cityField.find(':selected');
            const postcode = $selected.data('postcode');
            if (postcode) {
                $postcodeField.val(postcode);
            }
            lastCityProcessed = selectedVal;
        }
    }

    /* ─── City change (user-initiated) ────────────────────── */

    function handleCityChange(cityId) {
        if (!cityId || isUpdating || cityId === lastCityProcessed) return;
        lastCityProcessed = cityId;

        const $selected = $cityField.find(':selected');
        const postcode = $selected.data('postcode');

        if (postcode) {
            $postcodeField.val(postcode);
        }

        params.current_city_id = cityId;

        // Update hidden fields so the cart form submission carries the data
        $('#sameday_cart_city_id').val(cityId);
        const currentType = $('input[name="sameday_cart_type"]:checked').val() || params.current_type || 'address';
        $('#sameday_cart_delivery_type').val(currentType);

        // Persist to WC session immediately (for checkout page)
        saveSelectionToSession();

        // Trigger the cart update directly — no separate AJAX needed.
        // The hidden fields + calc_shipping_city get submitted with the form.
        // drushfs_sameday_vary_package_hash sets the session, then
        // calculate_shipping reads from session + POST data.
        isUpdating = true;
        cartUpdatePending = true;
        $("[name='update_cart']").prop('disabled', false).trigger('click');
    }

    /* ─── Radio visibility ────────────────────────────────── */

    function updateRadioVisibilityUI(hasEasybox, hasPudo) {
        const $selector = $('#sameday-cart-selector');
        const $easyboxOpt = $('.sameday-cart-option[data-type="easybox"]');
        const $pudoOpt = $('.sameday-cart-option[data-type="pudo"]');

        if (hasEasybox) $easyboxOpt.show(); else $easyboxOpt.hide();
        if (hasPudo) $pudoOpt.show(); else $pudoOpt.hide();

        // Hide the entire selector when address is the only option
        if (!hasEasybox && !hasPudo) {
            $selector.hide();
            // Ensure address is selected
            params.current_type = 'address';
            $('input[name="sameday_cart_type"][value="address"]').prop('checked', true);
        } else {
            $selector.show();
        }
    }

    /* ─── Reset (when user switches away from Sameday) ─────── */

    function resetCalculatorUI() {
        // Restore the calculator "Update" button
        $('button[name="calc_shipping"]').show();

        if (originalCityHtml !== null && $('#calc_shipping_city').is('select')) {
            try { $cityField.select2('destroy'); } catch (e) { /* ok */ }
            $cityField.parent().html(originalCityHtml);
            $cityField = $('#calc_shipping_city');
            $postcodeField.prop('readonly', false).css('background-color', '');
            // Don't reset lastStateProcessed/lastCityProcessed here —
            // if the user switches back to Sameday the data is still valid.
        }

        // Re-apply WC's native selectWoo on the state field so the searchable
        // dropdown is restored after we destroyed our custom Select2.
        var $state = $('select#calc_shipping_state');
        if ($state.length && $.fn.selectWoo) {
            if ($state.hasClass('select2-hidden-accessible')) {
                $state.select2('destroy');
            }
            $state.selectWoo({ width: '100%' });
        }

        // Also re-apply selectWoo on the country field in case it was stripped.
        var $country = $('select#calc_shipping_country');
        if ($country.length && $.fn.selectWoo) {
            if (!$country.hasClass('select2-hidden-accessible')) {
                $country.selectWoo({ width: '100%' });
            }
        }
    }

    /* ─── Delivery-type selector ──────────────────────────── */

    function renderSamedaySelector($container) {
        if ($('#sameday-cart-selector').length) {
            if (params.current_type) {
                $(`input[name="sameday_cart_type"][value="${params.current_type}"]`).prop('checked', true);
            }
            return;
        }

        const html = `
            <div id="sameday-cart-selector">
                <p class="sameday-cart-heading">${params.i18n.select_service || 'Select delivery type:'}</p>
                <div id="sameday-cart-options">
                    <div class="sameday-cart-option" data-type="address">
                        <label>
                            <input type="radio" name="sameday_cart_type" value="address">
                            <span>${params.i18n.to_address}</span>
                        </label>
                    </div>
                    <div class="sameday-cart-option" data-type="easybox" style="display: none;">
                        <label>
                            <input type="radio" name="sameday_cart_type" value="easybox">
                            <span>${params.i18n.to_easybox}</span>
                        </label>
                    </div>
                    <div class="sameday-cart-option" data-type="pudo" style="display: none;">
                        <label>
                            <input type="radio" name="sameday_cart_type" value="pudo">
                            <span>${params.i18n.to_pudo}</span>
                        </label>
                    </div>
                </div>
            </div>
        `;

        $container.append(html);

        if (params.current_type) {
            $(`input[name="sameday_cart_type"][value="${params.current_type}"]`).prop('checked', true);
        }

        $(document).off('change', 'input[name="sameday_cart_type"]').on('change', 'input[name="sameday_cart_type"]', function () {
            if (isUpdating) return;
            const type = $(this).val();
            params.current_type = type;

            // Update hidden fields and trigger cart update directly
            $('#sameday_cart_delivery_type').val(type);
            $('#sameday_cart_city_id').val(params.current_city_id || '');

            // Persist to WC session immediately (for checkout page)
            saveSelectionToSession();

            isUpdating = true;
            cartUpdatePending = true;
            $('#sameday-cart-selector').addClass('sameday-updating');
            $("[name='update_cart']").prop('disabled', false).trigger('click');
        });
    }

    /* ─── Select2 helpers (from sameday-common.js) ────────── */

    var modelMatcher  = SamedayModern.modelMatcher;

    /* ─── State select2 with transliteration + Sofia first ── */

    function initStateSelect2() {
        SamedayModern.initStateSelect2($('select#calc_shipping_state'), params.current_state);

        // Re-apply selectWoo on the country field — our select2 destroy/re-init
        // on the state field can strip selectWoo from sibling selects.
        var $country = $('select#calc_shipping_country');
        if ($country.length && $.fn.selectWoo) {
            if (!$country.hasClass('select2-hidden-accessible')) {
                $country.selectWoo({ width: '100%' });
            }
        }
    }

})(jQuery, window.drushfs_params);
