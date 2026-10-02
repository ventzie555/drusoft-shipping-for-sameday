/* global drushfs_params */
// noinspection CssInvalidHtmlTagReference

/**
 * Sameday checkout.
 *
 * The same flow as the Speedy and Econt siblings, on purpose: region, then a
 * city list that fills the postcode, then only the delivery options that city
 * really has, then a pickup list and a map limited to that city. Sameday has no
 * service chooser and no street nomenclature, so those two parts are absent.
 */

/**
 * @global drushfs_params
 * @type {object}
 * @property {string} ajax_url
 * @property {string} method_id
 * @property {Object} i18n
 * @property {string} i18n.to_address
 * @property {string} i18n.to_easybox
 * @property {string} i18n.to_pudo
 * @property {string} i18n.select_easybox
 * @property {string} i18n.select_pudo
 * @property {string} i18n.select_from_map
 * @property {string} i18n.select_city
 * @property {string} i18n.alert_select_city
 */

/**
 * @typedef {Object} SamedayAvailabilityData
 * @property {boolean} has_easybox
 * @property {boolean} has_pudo
 * @property {Array} easyboxes
 * @property {Array} pudos
 */

(function($, params) {
    'use strict';

    $(document).ready(function() {
        const samedayMethodId = params.method_id; // 'drushfs_sameday'
        let isSamedayActive = false;
        
        // State persistence across AJAX updates.
        // Initialize from server params (session data set by cart page).
        let lastDeliveryType = params.current_type || 'address';
        let lastOfficeId = params.current_office_id ? String(params.current_office_id) : '';

        // Cache to avoid redundant AJAX calls.
        let cachedState = '';
        let cachedCities = null;
        let cachedCityId = '';
        let cachedAvailability = null;

        // Remembered selection across a temporary switch to the sibling courier.
        // Populated on deactivate, consumed once on the next setup so the user
        // gets their province / city / delivery-type / office back when they
        // switch Sameday → Econt → Sameday.
        let savedSelection = null;

        // True while we are inside setupSamedayUI — prevents update_checkout
        // that our own code triggers from causing a re-entrant loop.
        let settingUp = false;

        // Current address context ('billing' or 'shipping')
        let currentContext = 'billing';

        // Store original HTML for both contexts to restore later
        const originals = {
            billing: {},
            shipping: {}
        };

        // Helper to capture originals
        function captureOriginals(context) {
            // Never snapshot a courier-built city select (ours or the sibling
            // Econt plugin's) as the "stock" field — that would corrupt the
            // restore. A courier city is always a select2 <select>; the stock
            // BG city field is a plain text input. Keep the clean snapshot taken
            // on page load instead.
            const $city = $('#' + context + '_city');
            if ($city.is('select') && $city.hasClass('select2-hidden-accessible')) {
                return;
            }
            if ($('#' + context + '_city_field').length) {
                originals[context].cityHtml = $('#' + context + '_city_field').html();
                originals[context].address1Html = $('#' + context + '_address_1_field').html();
                originals[context].address2Html = $('#' + context + '_address_2_field').html();
                originals[context].stateHtml = $('#' + context + '_state_field').html();
                
                // Capture priorities
                originals[context].priorities = {
                    state: $('#' + context + '_state_field').attr('data-priority'),
                    city: $('#' + context + '_city_field').attr('data-priority'),
                    address1: $('#' + context + '_address_1_field').attr('data-priority'),
                    address2: $('#' + context + '_address_2_field').attr('data-priority')
                };
            }
        }

        // Initial capture
        captureOriginals('billing');
        captureOriginals('shipping');

        // Listen for shipping method changes — use mousedown in CAPTURE phase
        // so our DOM cleanup runs before any other plugin's event handlers.
        document.addEventListener('mousedown', function(e) {
            const radio = e.target.closest('input[name^="shipping_method"]');
            if (!radio) return;

            // The radio hasn't changed value yet on mousedown, but we can
            // check whether the clicked radio is NOT Sameday.
            const isSameday = radio.value && radio.value.indexOf(samedayMethodId) !== -1;
            if (!isSameday && isSamedayActive) {
                deactivateSameday();
            }
        }, true);

        // Keyboard and programmatic switches never fire mousedown (arrow keys on
        // the radio group, assistive tech, scripts). Without this, our city select
        // lingered in the DOM after such a switch and the sibling courier's
        // takeover captured it as the "original" field — leaving a stale city list
        // (with OUR city ids) live under the other courier. Mirror the cleanup on
        // 'change' in CAPTURE phase so it still runs before other handlers.
        document.addEventListener('change', function(e) {
            const radio = e.target.closest('input[name^="shipping_method"]');
            if (!radio || !radio.checked) return;

            const isSameday = radio.value && radio.value.indexOf(samedayMethodId) !== -1;
            if (!isSameday && isSamedayActive) {
                deactivateSameday();
            }
        }, true);

        // Capture state BEFORE WC destroys the DOM
        $(document.body).on('update_checkout', function() {
            if (isSamedayActive && !settingUp) {
                const type = $('input[name="sameday_delivery_type"]:checked').val();
                if (type) lastDeliveryType = type;
                
                // Only capture office if the user explicitly selected one
                // (the change handler sets lastOfficeId directly, so we just
                // preserve whatever value it already has — don't read from DOM
                // because select2 may report a stale or auto-selected value).

                // Save current city id from the dropdown
                const cityVal = $('#' + currentContext + '_city').val();
                if (cityVal) cachedCityId = cityVal;
            }
            // Unbind state handler to prevent WC DOM teardown from triggering it.
            // Bound directly on the element now, so unbind there (not on body).
            $('#' + currentContext + '_state, #shipping_state').off('change.sameday');
        });

        // ===== SINGLE ENTRY POINT: updated_checkout =====
        // WooCommerce fires this after every checkout AJAX refresh,
        // including the initial one on page load. This is where we
        // set up or restore the Sameday UI — never from document.ready.
        $(document.body).on('updated_checkout', function() {
            if (settingUp) return; // guard against re-entrance

            updateContext();

            const samedaySelected = isSamedaySelected();

            // ALWAYS rebind the state-change handler when Sameday is the chosen
            // method. The update_checkout handler above unbinds change.sameday on
            // every cycle, and the short-circuit below can skip setupSamedayUI
            // (which is the only other place that rebinds). Without this, changing
            // the province after the cascade is set leaves change.sameday unbound,
            // so the city/office never clear or reload for the new province.
            if ( samedaySelected && isSamedayActive ) {
                bindStateChangeHandler();
            }

            if (!samedaySelected) {
                if (!isSamedayActive) {
                    clearStalePlaceholder();
                    captureOriginals('billing');
                    captureOriginals('shipping');
                }
                if (isSamedayActive) {
                    deactivateSameday();
                }
                return;
            }

            // Sameday is selected — set up / restore UI.
            // Only skip the rebuild if Sameday is ALREADY active AND our own city
            // select is in the DOM (WC refreshed order-review without rebuilding
            // billing). The isSamedayActive guard is essential: after switching
            // away to Econt and back, our old drushfs-city select can linger in
            // the DOM — without the guard we'd mistake it for a live setup, skip
            // setupSamedayUI, and never restore the remembered selection.
            if (isSamedayActive && $('#' + currentContext + '_city').hasClass('drushfs-city')) {
                return;
            }

            // WC rebuilt the DOM. Capture clean originals, then set up Sameday.
            captureOriginals('billing');
            captureOriginals('shipping');

            setupSamedayUI();
        });

        // Listen for Country Change (WC re-sorts fields on this event)
        $(document.body).on('country_to_state_changed', function() {
            if (isSamedayActive) {
                setTimeout(function() {
                    reorderFieldsForSameday();
                    initStateSelect2WithTransliteration();
                    makeRegionRequired();
                }, 100);
            }
        });

        // Listen for "Ship to different address" toggle
        // Payment-method change → re-quote. Sameday's calculate_shipping reads
        // payment_method from POST and toggles COD additional services on the
        // API call, and the package hash already varies by payment_method, but
        // WC doesn't trigger update_checkout on its own when the payment radio
        // changes — we have to. Delegated on body so it survives WC's payment-
        // block re-renders.
        $(document.body).on('change', 'input[name="payment_method"]', function() {
            if (isSamedayActive) {
                $(document.body).trigger('update_checkout');
            }
        });

        $('form.checkout').on('change', '#ship-to-different-address-checkbox', function() {
            // If Sameday is active, we need to switch contexts
            if (isSamedayActive) {
                // Deactivate on current context (restore fields)
                deactivateSameday();
                
                // Update context
                updateContext();
                
                // Re-activate on new context
                setupSamedayUI();
            } else {
                updateContext();
            }
        });

        function updateContext() {
            if ($('#ship-to-different-address-checkbox').is(':checked')) {
                currentContext = 'shipping';
            } else {
                currentContext = 'billing';
            }
        }

        function isSamedaySelected() {
            // When only one method exists, WC renders a hidden input (no :checked).
            const selectedMethod = $('input[name^="shipping_method"][type="radio"]:checked, input[name^="shipping_method"][type="hidden"]').first().val();
            return !!(selectedMethod && selectedMethod.indexOf(samedayMethodId) !== -1);
        }

        // Initial check on page load
        updateContext();

        // Grey out the order-review/payment while we rebuild the UI and re-quote.
        // Without this the restore shows an intermediate "address" price (computed
        // before the office field is rebuilt) for a split second before settling
        // on the office price — a visible flicker. WC removes our overlay when it
        // replaces the review HTML on the next update_checkout.
        function blockOrderReview() {
            const $targets = $('.woocommerce-checkout-review-order-table, .woocommerce-checkout-payment');
            if ($targets.length && typeof $targets.block === 'function') {
                $targets.block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });
            }
        }
        function unblockOrderReview() {
            const $targets = $('.woocommerce-checkout-review-order-table, .woocommerce-checkout-payment');
            if ($targets.length && typeof $targets.unblock === 'function') {
                $targets.unblock();
            }
        }

        /**
         * Single setup/restore function for the Sameday UI.
         * Called only from updated_checkout — never from document.ready.
         * Uses caches when available to avoid redundant AJAX calls.
         */
        // WooCommerce pre-fills the street from the customer's last order. After a
        // locker order that is our placeholder, and on a page that opens with
        // another courier selected we were never active to clean it up.
        function clearStalePlaceholder() {
            const $address1 = $('#' + currentContext + '_address_1');
            if ([params.i18n.to_easybox, params.i18n.to_pudo].indexOf($address1.val()) !== -1) {
                $address1.val('');
                $('#' + currentContext + '_address_2').val('');
            }
        }

        function setupSamedayUI() {
            isSamedayActive = true;
            settingUp = true;
            // Present from the first moment; later steps move it into place.
            setTimeout(placeMapButton, 0);

            // A locker type chosen below writes the placeholder back.
            clearStalePlaceholder();

            // Speedy and Econt (up to their September 2026 releases) recognise
            // only each other: handing over to anyone else, they restore the
            // stock field order. If that lands after our layout, lay it out again.
            setTimeout(function() {
                if (isSamedayActive) {
                    reorderFieldsForSameday();
                }
            }, 60);

            // Cover the just-rendered intermediate price immediately (same tick,
            // before paint), so the restore doesn't flicker through it. The full
            // path ends in a single update_checkout that re-quotes and removes the
            // overlay; the early-return paths below unblock explicitly.
            blockOrderReview();

            // Tell the sibling Econt plugin that Sameday now owns the layout, so
            // its deactivate (which runs after ours when switching) won't restore
            // the stock field order on top of ours.
            window.__drushfActiveCourier = 'sameday';

            reorderFieldsForSameday();
            initStateSelect2WithTransliteration();
            makeRegionRequired();

            // Restore THIS courier's own remembered selection. Province and city
            // come ONLY from our own memory (savedSelection) or the server
            // session — NEVER from the current DOM value, which belongs to the
            // sibling courier. Sameday and Econt keep fully separate data: picking
            // a county under Sameday must not pre-fill it under Econt.
            let restoredState = '';
            if (savedSelection) {
                if (savedSelection.deliveryType) lastDeliveryType = savedSelection.deliveryType;
                if (savedSelection.officeId) lastOfficeId = savedSelection.officeId;
                if (savedSelection.cityId) cachedCityId = savedSelection.cityId;
                restoredState = savedSelection.state || '';
                savedSelection = null;
            }

            // WC uses '*' as a "no state" sentinel for guests — treat it as empty.
            const sessionState = (params.current_state && params.current_state !== '*') ? params.current_state : '';
            const effectiveState = restoredState || sessionState;

            // Apply (or CLEAR) the shared state field to match our own data, so we
            // never inherit the sibling courier's leftover province.
            const $stateEl = $('#' + currentContext + '_state');
            if (($stateEl.val() || '') !== effectiveState) {
                $stateEl.val(effectiveState).trigger('change.select2');
            }

            // Postcode is a shared field too — clear it so we don't inherit the
            // sibling courier's. The restore chain below re-fills it from our own
            // pre-selected city/office.
            $('#' + currentContext + '_postcode').val('');

            // City to pre-select comes only from our own cache/session — not the
            // DOM value, which would be the sibling courier's city.
            const preSelectCity = cachedCityId || params.current_city_id;

            if (!effectiveState) {
                $('#' + currentContext + '_city_field').hide();
                settingUp = false;
                bindStateChangeHandler();
                unblockOrderReview();
                return;
            }

            // Load cities (cached or AJAX) then continue the chain
            loadCities(effectiveState, function(cities) {
                if (!cities) { settingUp = false; bindStateChangeHandler(); unblockOrderReview(); return; }

                replaceCityInputWithSelect(cities, preSelectCity, true); // skip auto-trigger

                const $citySelect = $('#' + currentContext + '_city');
                const selectedCityId = $citySelect.val();

                if (!selectedCityId) {
                    // No city matched — user will have to pick one
                    settingUp = false;
                    bindStateChangeHandler();
                    unblockOrderReview();
                    return;
                }

                // Load availability (cached or AJAX) then continue
                loadAvailability(selectedCityId, function(availData) {
                    if (availData) {
                        presentDeliveryOptions(availData);
                    }

                    settingUp = false;
                    bindStateChangeHandler();

                    // Now trigger a single update_checkout to get the correct price
                    $(document.body).trigger('update_checkout');
                });
            });
        }

        /**
         * Load cities for a state — uses cache if available.
         * Calls callback(cities) when done.
         */
        function loadCities(stateCode, callback) {
            let deferred = $.Deferred();

            if (stateCode === cachedState && cachedCities) {
                callback(cachedCities);
                deferred.resolve();
                return deferred.promise();
            }

            $.ajax({
                url: params.ajax_url,
                type: 'POST',
                data: { action: 'drushfs_get_cities', nonce: params.nonce, region: stateCode },
                success: function(response) {
                    if (response.success) {
                        cachedState = stateCode;
                        cachedCities = response.data;
                        callback(response.data);
                    } else {
                        callback(null);
                    }
                    deferred.resolve();
                },
                error: function() { callback(null); deferred.resolve(); }
            });

            return deferred.promise();
        }

        /**
         * Load availability for a city — uses cache if available.
         * Calls callback(data) when done.
         */
        function loadAvailability(cityId, callback) {
            if (String(cityId) === String(cachedCityId) && cachedAvailability) {
                callback(cachedAvailability);
                return;
            }

            $.ajax({
                url: params.ajax_url,
                type: 'POST',
                data: { action: 'drushfs_check_availability', nonce: params.nonce, city_id: cityId },
                success: function(response) {
                    if (response.success) {
                        cachedCityId = cityId;
                        cachedAvailability = response.data;
                        callback(response.data);
                    } else {
                        callback(null);
                    }
                },
                error: function() { callback(null); }
            });
        }

        /**
         * Bind the state change handler (user changes state dropdown).
         */
        function bindStateChangeHandler() {
            // Bind directly on the state element (not delegated on body) — this
            // way the handler survives select2 init/destroy cycles. When we
            // re-init the state select2 after the first province change, the
            // change event stops bubbling to body, which silently killed the old
            // delegated handler so changing the province a second time did
            // nothing. updated_checkout calls this every cycle, so off-then-on is
            // idempotent. (Mirrors the Drusoft Shipping for Econt sibling.)
            const $state = $('#' + currentContext + '_state');
            $state.off('change.sameday');
            $state.on('change.sameday', function() {
                const state = $(this).val();
                if (!isSamedayActive) return;

                // State changed — remove stale delivery options
                $('#sameday-delivery-type-field').remove();
                $('#sameday-office-field').remove();
                $('#sameday-map-button-wrapper').remove();
                lastDeliveryType = 'address';
                lastOfficeId = '';

                // The options are gone until a city is chosen, so the street
                // must not stay hidden behind a locker placeholder.
                clearStalePlaceholder();
                $('#' + currentContext + '_address_1_field, #' + currentContext + '_address_2_field').show();

                // Invalidate caches
                cachedState = '';
                cachedCities = null;
                cachedCityId = '';
                cachedAvailability = null;

                // Clear city field — destroy select2 if present, then reset
                const $cityEl = $('#' + currentContext + '_city');
                if ($cityEl.is('select') && $cityEl.hasClass('select2-hidden-accessible')) {
                    $cityEl.val('').trigger('change.select2');
                    $cityEl.select2('destroy');
                }
                const $cityField = $('#' + currentContext + '_city_field');
                if (originals[currentContext] && originals[currentContext].cityHtml) {
                    $cityField.html(originals[currentContext].cityHtml);
                    $('#' + currentContext + '_city').val('');
                }

                // Clear postcode — it belongs to the previous city
                $('#' + currentContext + '_postcode').val('');

                if (state) {
                    $('#' + currentContext + '_city_field').show();
                    handleStateChange(state);
                } else {
                    $('#' + currentContext + '_city_field').hide();
                }
                placeMapButton();
                $(document.body).trigger('update_checkout');
            });
        }



        function deactivateSameday() {
            isSamedayActive = false;

            // Snapshot the current selection BEFORE we tear the fields down, so
            // we can restore it if the user switches back to Sameday. Captured
            // separately from the load-bearing resets below — those must still
            // run for a clean teardown.
            // Read from OUR private caches FIRST, not the shared DOM fields. The
            // sibling courier's updated_checkout handler runs before our deactivate
            // and may have already written ITS province/city into #billing_state /
            // #billing_city — reading the DOM here would capture the sibling's data
            // as ours. cachedState / cachedCityId hold only THIS courier's values.
            const $remCity = $('#' + currentContext + '_city');
            const domCity = ($remCity.is('select') && $remCity.hasClass('drushfs-city')) ? $remCity.val() : '';
            const remState = cachedState || $('#' + currentContext + '_state').val();
            const remCity = cachedCityId || domCity;
            if (remState || remCity || lastDeliveryType !== 'address' || lastOfficeId) {
                savedSelection = {
                    state: remState || '',
                    cityId: remCity || '',
                    deliveryType: lastDeliveryType,
                    officeId: lastOfficeId
                };
            }

            $('#' + currentContext + '_state, #shipping_state').off('change.sameday');

            // If the sibling Econt plugin is the courier now taking over, it has
            // already (synchronously) re-laid-out the billing fields for itself.
            // Running the full stock-field restore here would clobber Econt's
            // field order and re-show the address field. In that case only remove
            // OUR injected UI. Otherwise (switching to a non-courier method) do
            // the full restore so the stock checkout returns to normal.
            if (window.__drushfActiveCourier && window.__drushfActiveCourier !== 'sameday') {
                $('#sameday-delivery-type-field, #sameday-office-field, #sameday-map-button-wrapper').remove();
            } else {
                restoreOriginalFields();
                restoreFieldOrder();
            }

            lastDeliveryType = 'address';
            lastOfficeId = '';
            sessionStorage.removeItem('sameday_delivery_type');
            sessionStorage.removeItem('sameday_office_id');
        }

        function reorderFieldsForSameday() {
            const $stateField = $('#' + currentContext + '_state_field');
            const $countryField = $('#' + currentContext + '_country_field');
            const $cityField = $('#' + currentContext + '_city_field');

            $stateField.insertAfter($countryField);
            $stateField.attr('data-priority', 41);
            
            $cityField.insertAfter($stateField);
            $cityField.attr('data-priority', 42);
            
            $('#sameday-delivery-type-field').insertAfter($cityField);
        }

        function restoreFieldOrder() {
            const $stateField = $('#' + currentContext + '_state_field');
            const $countryField = $('#' + currentContext + '_country_field');
            const $cityField = $('#' + currentContext + '_city_field');
            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');
            
            const originalPrio = originals[currentContext].priorities;

            $address1Field.insertAfter($countryField);
            $address2Field.insertAfter($address1Field);
            $cityField.insertAfter($address2Field);
            if (originalPrio && originalPrio.city) $cityField.attr('data-priority', originalPrio.city);

            $stateField.insertAfter($cityField);
            if (originalPrio && originalPrio.state) $stateField.attr('data-priority', originalPrio.state);
        }

        /**
         * Re-init the state select2 with our transliteration-aware matcher.
         * WooCommerce initializes it without transliteration support.
         */
        function initStateSelect2WithTransliteration() {
            SamedayModern.initStateSelect2(
                $('#' + currentContext + '_state'),
                params.current_state
            );
        }

        function makeRegionRequired() {
            const $field = $('#' + currentContext + '_state_field');
            if (!$field.hasClass('validate-required')) {
                $field.addClass('validate-required');
                $field.find('label .optional').hide();
                if ($field.find('label .required').length === 0) {
                    $field.find('label').append('&nbsp;<abbr class="required" title="required">*</abbr>');
                }
            } else {
                $field.find('label .optional').hide();
                if ($field.find('label .required').length === 0) {
                    $field.find('label').append('&nbsp;<abbr class="required" title="required">*</abbr>');
                }
            }
        }

        function restoreOriginalFields() {
            const $cityInput = $('#' + currentContext + '_city');
            const $cityField = $('#' + currentContext + '_city_field');
            
            // Only restore the city if WE own it. If the sibling Econt plugin
            // has already taken over the field (its own select), leave it alone —
            // tearing it down here would blank out the courier the user just
            // switched to.
            if ($cityInput.is('select') && $cityInput.hasClass('drushfs-city')) {
                if ($cityInput.data('select2')) {
                    $cityInput.select2('destroy');
                }
                $cityField.html(originals[currentContext].cityHtml);
                $('#' + currentContext + '_city').prop('disabled', false).val('');
            }

            const $stateField = $('#' + currentContext + '_state_field');
            $stateField.find('label .optional').show();
            $stateField.find('label .required').remove();
            $stateField.removeClass('validate-required');

            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');

            $address1Field.html(originals[currentContext].address1Html).show();
            $address2Field.html(originals[currentContext].address2Html).show();
            
            const addr1 = $address1Field.find('#' + currentContext + '_address_1').val();
            if (addr1 === params.i18n.to_easybox || addr1 === params.i18n.to_pudo) {
                $address1Field.find('#' + currentContext + '_address_1').val('');
                $address2Field.find('#' + currentContext + '_address_2').val('');
            }
            
            $('#' + currentContext + '_postcode').val('');
            
            $('#sameday-delivery-type-field').remove();
            $('#sameday-office-field').remove();
            $('#sameday-map-button-wrapper').remove();

            $cityField.show();

            // Re-apply WC's native selectWoo on the state field so the searchable
            // dropdown is restored after we destroyed our custom Select2.
            var $stateEl = $('#' + currentContext + '_state');
            if ($stateEl.is('select') && $.fn.selectWoo) {
                if ($stateEl.hasClass('select2-hidden-accessible')) {
                    $stateEl.select2('destroy');
                }
                $stateEl.selectWoo({ width: '100%' });
            }
        }

        function handleStateChange(stateCode, preSelectedCity) {
            if (!isSamedayActive || !stateCode) {
                return;
            }

            // Use loadCities which handles caching
            return loadCities(stateCode, function(cities) {
                if (cities) {
                    replaceCityInputWithSelect(cities, preSelectedCity);
                }
            });
        }

        function replaceCityInputWithSelect(cities, preSelectedCity, skipAutoTrigger) {
            const $cityField = $('#' + currentContext + '_city_field');
            const $cityWrapper = $cityField.find('.woocommerce-input-wrapper');
            const currentCity = preSelectedCity || $('#' + currentContext + '_city').val() || params.current_city_id;

            let options = '<option value="">' + params.i18n.select_city + '</option>';
            $.each(cities, function(index, city) {
                let selected = '';
                if (currentCity) {
                    if (String(city.id) === String(currentCity)) {
                        selected = 'selected';
                    } else if (city.name.toUpperCase() === String(currentCity).toUpperCase()) {
                        selected = 'selected';
                    }
                }
                
                const esc = SamedayModern.esc;
                options += '<option value="' + esc(city.id) + '" data-postcode="' + esc(city.postcode || '') + '" ' + selected + '>' + esc(city.name) + ' ' + (city.postcode ? '(' + esc(city.postcode) + ')' : '') + '</option>';
            });

            const selectHtml = '<select name="' + currentContext + '_city" id="' + currentContext + '_city" class="select2-hidden-accessible drushfs-city" data-placeholder="' + params.i18n.select_city + '">' + options + '</select>';
            
            $cityWrapper.html(selectHtml);

            const $newCitySelect = $('#' + currentContext + '_city');
            $newCitySelect.select2({
                width: '100%',
                matcher: modelMatcher
            });

            $newCitySelect.on('change', function() {
                handleCityChange($(this).val());
            });
            placeMapButton();
            
            // When called from setupSamedayUI, skip auto-trigger — the caller controls the chain.
            if (!skipAutoTrigger) {
                if ($newCitySelect.val()) {
                     handleCityChange($newCitySelect.val());
                } else {
                     settingUp = false;
                }
            }

            // Set postcode for pre-selected city. During a restore (skipAutoTrigger)
            // set the value WITHOUT firing 'change' — that would kick off an extra
            // intermediate update_checkout (computing the wrong price before the
            // office is rebuilt). The single update_checkout at the end of the
            // restore sends this postcode value anyway.
            if ($newCitySelect.val()) {
                const $sel = $newCitySelect.find(':selected');
                const pc = $sel.data('postcode');
                if (pc) {
                    const $pc = $('#' + currentContext + '_postcode');
                    if (skipAutoTrigger) {
                        $pc.val(pc);
                    } else {
                        $pc.val(pc).trigger('change');
                    }
                }
            }
        }

        function handleCityChange(cityId) {
            if (!cityId) return;

            const $selectedOption = $('#' + currentContext + '_city').find(':selected');
            const postcode = $selectedOption.data('postcode');
            if (postcode) {
                $('#' + currentContext + '_postcode').val(postcode).trigger('change');
            }

            loadAvailability(cityId, function(data) {
                if (data) {
                    presentDeliveryOptions(data);
                }
                $(document.body).trigger('update_checkout');
                settingUp = false;
            });
        }

        function presentDeliveryOptions(data) {
            $('#sameday-delivery-type-field').remove();
            $('#sameday-office-field').remove();
            $('#sameday-map-button-wrapper').remove();

            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');

            if (!data.has_easybox && !data.has_pudo) {
                $address1Field.show();
                $address2Field.show();
                $address1Field.find('input').val('');
                $address2Field.find('input').val('');
                placeMapButton();
                return;
            }

            let radios = '<span class="woocommerce-input-wrapper" id="sameday-delivery-type-wrapper">';
            
            radios += '<input type="radio" name="sameday_delivery_type" id="sameday_delivery_type_address" value="address" checked="checked" style="margin-left: 0;">' +
                      '<label for="sameday_delivery_type_address" style="display: inline-block; margin-right: 15px; margin-left: 5px;">' + params.i18n.to_address + '</label>';

            if (data.has_easybox) {
                radios += '<input type="radio" name="sameday_delivery_type" id="sameday_delivery_type_easybox" value="easybox">' +
                          '<label for="sameday_delivery_type_easybox" style="display: inline-block; margin-right: 15px; margin-left: 5px;">' + params.i18n.to_easybox + '</label>';
                $('#' + currentContext + '_city_field').data('easyboxes', data.easyboxes || []);
            }
            if (data.has_pudo) {
                radios += '<input type="radio" name="sameday_delivery_type" id="sameday_delivery_type_pudo" value="pudo">' +
                          '<label for="sameday_delivery_type_pudo" style="display: inline-block; margin-left: 5px;">' + params.i18n.to_pudo + '</label>';
                $('#' + currentContext + '_city_field').data('pudos', data.pudos || []);
            }
            radios += '</span>';

            const radioHtml = '<p class="form-row form-row-wide" id="sameday-delivery-type-field">' +
                '<label>' + params.i18n.delivery_method + '</label>' + radios + '</p>';

            $('#' + currentContext + '_city_field').after(radioHtml);

            $('input[name="sameday_delivery_type"]').on('change', function() {
                handleDeliveryTypeChange($(this).val());
                // Delivery type changed → recalculate shipping
                $(document.body).trigger('update_checkout');
            });
            
            // A remembered type this city does not have falls back to address.
            if (!$('input[name="sameday_delivery_type"][value="' + lastDeliveryType + '"]').length) {
                lastDeliveryType = 'address';
                lastOfficeId = '';
            }

            // Trigger initial state
            if (lastDeliveryType !== 'address') {
                $('input[name="sameday_delivery_type"][value="' + lastDeliveryType + '"]').prop('checked', true);
            }
            handleDeliveryTypeChange(lastDeliveryType);
        }

        function handleDeliveryTypeChange(type) {
            $('#sameday-office-field').remove();
            $('#sameday-map-button-wrapper').remove();

            sessionStorage.setItem('sameday_delivery_type', type);
            lastDeliveryType = type;
            
            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');

            if (type === 'address') {
                $address1Field.show();
                $address2Field.show();
                $address1Field.find('input').val('');
                $address2Field.find('input').val('');
                placeMapButton();
            } else {
                $address1Field.hide();
                $address2Field.hide();
                // Unregister — address fields are hidden, no autocomplete needed
                    if (type === 'easybox') {
                    $address1Field.find('input').val(params.i18n.to_easybox);
                } else if (type === 'pudo') {
                    $address1Field.find('input').val(params.i18n.to_pudo);
                }

                let points = [];
                if (type === 'easybox') {
                    points = $('#' + currentContext + '_city_field').data('easyboxes');
                } else if (type === 'pudo') {
                    points = $('#' + currentContext + '_city_field').data('pudos');
                }

                showPointsDropdown(points, type);
            }
        }

        function showPointsDropdown(points, type) {
            const label = (type === 'easybox') ? params.i18n.select_easybox : params.i18n.select_pudo;
            let options = '<option value="" selected></option>';

            $.each(points, function(index, point) {
                options += '<option value="' + SamedayModern.esc(point.id) + '">' + SamedayModern.esc(point.label) + '</option>';
            });

            const selectHtml = '<p class="form-row form-row-wide" id="sameday-office-field">' +
                '<label for="sameday_office_id">' + label + '&nbsp;<abbr class="required" title="required">*</abbr></label>' +
                '<span class="woocommerce-input-wrapper">' +
                '<select name="sameday_office_id" id="sameday_office_id">' + options + '</select>' +
                '</span></p>';

            $('#sameday-delivery-type-field').after(selectHtml);
            
            const $officeSelect = $('#sameday_office_id');
            $officeSelect.select2({
                width: '100%',
                placeholder: label + '...',
                allowClear: true,
                matcher: modelMatcher
            });

            // Pre-select saved office (if any) BEFORE binding the change handler.
            // Otherwisek, ensure the placeholder is shown (no office selected).
            if (lastOfficeId) {
                $officeSelect.val(lastOfficeId).trigger('change.select2');
                // The change handler below is what writes the location's name
                // into the second address line, and a restore does not fire
                // it — the order then said "До easybox" without saying which.
                const restoredText = $officeSelect.find('option:selected').text();
                if ($officeSelect.val() && restoredText) {
                    $('#' + currentContext + '_address_2_field').find('input').val(restoredText);
                }
            } else {
                $officeSelect.val('').trigger('change.select2');
            }

            // Now bind the change handler for user-initiated changes
            $officeSelect.on('change', function() {
                const officeVal = $(this).val();
                const selectedText = $(this).find('option:selected').text();
                const deliveryType = $('input[name="sameday_delivery_type"]:checked').val();
                
                const $address1Field = $('#' + currentContext + '_address_1_field');
                const $address2Field = $('#' + currentContext + '_address_2_field');

                if (deliveryType === 'easybox') {
                    $address1Field.find('input').val(params.i18n.to_easybox);
                } else if (deliveryType === 'pudo') {
                    $address1Field.find('input').val(params.i18n.to_pudo);
                }
                
                $address2Field.find('input').val(officeVal ? selectedText : '');

                lastOfficeId = officeVal || '';
                sessionStorage.setItem('sameday_office_id', lastOfficeId);

                // Office/automat selected → recalculate shipping
                $(document.body).trigger('update_checkout');
            });

            placeMapButton();
        }

        /**
         * Place / move the map button. Idempotent — called from every setup
         * path so the button follows the layout. As in the Econt sibling it is
         * there whenever Sameday is the active courier, before any city or
         * delivery type is chosen: the map covers the whole country and a pick
         * sets region, city and type by itself.
         */
        function placeMapButton() {
            $('#sameday-map-button-wrapper').remove();
            if (!isSamedayActive) return;

            const html = '<p class="form-row form-row-wide" id="sameday-map-button-wrapper" style="margin-top: 10px;">' +
                '<button type="button" id="sameday-open-map" class="button" style="width: 100%;">' + params.i18n.select_from_map + '</button>' +
                '</p>';

            const $anchor = $('#sameday-office-field').length
                ? $('#sameday-office-field')
                : ($('#sameday-delivery-type-field').length
                    ? $('#sameday-delivery-type-field')
                    : $('#' + currentContext + '_city_field'));
            $anchor.after(html);

            $('#sameday-open-map').off('click.samedayMap').on('click.samedayMap', openSamedayMap);
        }

        // Every pickup location in the country, fetched once per page and only
        // when the customer first opens the map.
        let allPointsCache = null;
        let allPointsPromise = null;

        function fetchAllPoints() {
            if (allPointsCache) return Promise.resolve(allPointsCache);
            if (allPointsPromise) return allPointsPromise;
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

        /**
         * Own Leaflet map (assets/js/map.js), the module the Speedy and Econt
         * plugins use. Like Econt's it shows the whole country, opens zoomed on
         * the city already chosen, and lets the customer change their mind
         * about the kind of location without reopening.
         */
        function openSamedayMap() {
            if (!window.DrushfsMap) return;
            const currentType = $('input[name="sameday_delivery_type"]:checked').val() || lastDeliveryType || 'address';

            let defaultFilter = 'both';
            let title = params.i18n.select_from_map;
            if (currentType === 'pudo') { defaultFilter = 'office'; title = params.i18n.map_title_pudo || title; }
            else if (currentType === 'easybox') { defaultFilter = 'automat'; title = params.i18n.map_title_easybox || title; }

            fetchAllPoints().then(function (all) {
                if (!all.length) return;
                window.DrushfsMap.open(all, handleMapPick, {
                    title:         title,
                    hint:          params.i18n.map_hint,
                    pickLabel:     params.i18n.map_pick,
                    errorLabel:    params.i18n.map_error,
                    defaultFilter: defaultFilter,
                    focusCityId:   $('#' + currentContext + '_city').val() || null,
                    i18n: {
                        offices:            params.i18n.map_filter_office,
                        automats:           params.i18n.map_filter_automat,
                        both:               params.i18n.map_filter_both,
                        search_placeholder: params.i18n.map_search_placeholder,
                        results_count:      params.i18n.map_results_count,
                        search_no_results:  params.i18n.map_search_no_results,
                    },
                });
            });
        }

        // Picking a marker may require switching region + city + delivery type.
        // Chain those changes with short polls for the dependent UI to render
        // (the Econt sibling's handleMapPick pattern).
        function handleMapPick(point) {
            const targetCityId = String(point.city_id);
            const targetRegion = point.region_code;
            const targetType   = (point.office_type === 'APS') ? 'easybox' : 'pudo';

            const $state = $('#' + currentContext + '_state');
            const $city  = $('#' + currentContext + '_city');
            const currentState = $state.val();
            const currentCity  = $city.is('select') ? String($city.val() || '') : '';

            function setPointId() {
                const $sel = $('#sameday_office_id');
                if (!$sel.length) return;
                const targetId = String(point.id);
                if (!$sel.find('option[value="' + targetId + '"]').length) {
                    const label = point.name + (point.address ? ' — ' + point.address : '');
                    $sel.append(new Option(label, point.id, true, true));
                }
                $sel.val(targetId).trigger('change.select2');
                $sel.trigger('change');
            }

            function setDeliveryType(done) {
                const cur = $('input[name="sameday_delivery_type"]:checked').val();
                if (cur === targetType && $('#sameday_office_id').length) { done(); return; }
                $('input[name="sameday_delivery_type"][value="' + targetType + '"]').prop('checked', true).trigger('change');
                waitFor(function () { return !!$('#sameday_office_id').length; }, done);
            }

            // Simple promise-less poll, 200ms × up to 50 (=10s).
            function waitFor(predicate, done, attempts) {
                attempts = (attempts === undefined) ? 50 : attempts;
                if (predicate()) { setTimeout(done, 100); return; }
                if (attempts <= 0) { return; }
                setTimeout(function () { waitFor(predicate, done, attempts - 1); }, 200);
            }

            function typeRadioReady() {
                return $('input[name="sameday_delivery_type"][value="' + targetType + '"]').length > 0;
            }

            const sameState = !targetRegion || currentState === targetRegion;
            const sameCity  = currentCity === targetCityId;

            if (sameState && sameCity) {
                setDeliveryType(setPointId);
                return;
            }

            if (!sameState) {
                $state.val(targetRegion).trigger('change');
                waitFor(function () {
                    return $('#' + currentContext + '_city option[value="' + targetCityId + '"]').length > 0;
                }, function () {
                    $('#' + currentContext + '_city').val(targetCityId).trigger('change');
                    waitFor(typeRadioReady, function () { setDeliveryType(setPointId); });
                });
                return;
            }

            // Same region, different city.
            $city.val(targetCityId).trigger('change');
            waitFor(typeRadioReady, function () { setDeliveryType(setPointId); });
        }

        // --- Select2 matcher (from sameday-common.js) ---
        var modelMatcher = SamedayModern.modelMatcher;

    });
})(jQuery, window.drushfs_params);




