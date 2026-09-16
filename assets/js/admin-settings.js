/**
 * Drusoft Shipping for Sameday – Admin Settings Script
 *
 * Handles dynamic field visibility and grouping in the shipping method settings modal.
 */
(function( $ ) {
	'use strict';

	/**
	 * When the WooCommerce backbone modal loads, attach change listeners
	 * to the relevant fields to toggle visibility of dependent rows.
	 */
	$( document.body ).on( 'wc_backbone_modal_loaded', function( event, target ) {
		if ( 'wc-modal-shipping-method-settings' !== target ) {
			return;
		}

		// --- Helper Functions ---

		/**
		 * Toggle a row based on a condition.
		 * Handles div-based (label+fieldset) layouts.
		 */
		function toggleRow( fieldKey, show ) {
			var $field = $( '[id$="' + fieldKey + '"]' );
			
			if ( $field.length > 1 ) {
				$field = $field.filter(function() {
					return $(this).closest('.wc-backbone-modal-content').length > 0;
				});
			}
			
			if ( ! $field.length ) {
				$field = $( '[name*="' + fieldKey + '"]' );
			}

			if ( ! $field.length ) return;

			// Div-based layout (label + fieldset siblings)
			var $fieldset = $field.closest( 'fieldset' );
			var fieldId   = $field.attr( 'id' );
			var $label    = $( 'label[for="' + fieldId + '"]' ).not( $fieldset.find('label') );
			
			if ( show ) {
				$fieldset.stop().slideDown(200);
				$label.stop().slideDown(200);
			} else {
				$fieldset.stop().slideUp(200);
				$label.stop().slideUp(200);
			}
		}

		/**
		 * Wraps a trigger field and its dependents in a visual group.
		 */
		function createVisualGroup( triggerKey, dependentKeys ) {
			// Find the trigger elements
			var $triggerField = $( '[id$="' + triggerKey + '"]' );
			
			// Filter for modal context to avoid duplicates if field exists elsewhere
			if ( $triggerField.length > 1 ) {
				$triggerField = $triggerField.filter(function() {
					return $(this).closest('.wc-backbone-modal-content').length > 0;
				});
			}
			
			if ( ! $triggerField.length ) return;

			// Check if already grouped to prevent errors or double wrapping
			if ( $triggerField.closest('.sameday-settings-group').length ) {
				return;
			}

			var $triggerFieldset = $triggerField.closest( 'fieldset' );
			
			// Find the main label, ensuring we don't grab the label wrapping the checkbox inside the fieldset
			var $triggerLabel = $( 'label[for="' + $triggerField.attr('id') + '"]' ).not( $triggerFieldset.find('label') );

			// Start collection with trigger elements
			var $elementsToGroup = $triggerLabel.add($triggerFieldset);

			// Add dependent elements
			$.each( dependentKeys, function( i, key ) {
				var $field = $( '[id$="' + key + '"]' );
				if ( $field.length ) {
					var $fieldset = $field.closest( 'fieldset' );
					// Same logic for dependents: only grab the external label
					var $label = $( 'label[for="' + $field.attr('id') + '"]' ).not( $fieldset.find('label') );
					
					$elementsToGroup = $elementsToGroup.add($label).add($fieldset);
				}
			});

			// Wrap all collected elements in a single container
			// wrapAll inserts the wrapper at the position of the first element in the set
			$elementsToGroup.wrapAll('<div class="sameday-settings-group"></div>');
		}



		// --- Grouping Logic ---


		// --- Visibility Logic ---

		function setupDependency( sourceKey, targetKey, expectedValue ) {
			var $source = $( '[id$="' + sourceKey + '"]' );
			
			function update() {
				var val = $source.val();
				if ( $source.is(':checkbox') ) {
					val = $source.is(':checked') ? 'yes' : 'no';
				}
				
				var show = false;
				if ( Array.isArray( expectedValue ) ) {
					show = expectedValue.includes( val );
				} else {
					show = ( val === expectedValue );
				}
				
				toggleRow( targetKey, show );
			}

			if ( $source.length ) {
				$source.change( update );
				update();
			}
		}

		function setupCheckboxToggle( checkboxId, targetKeys ) {
			var selector = checkboxId.indexOf('woocommerce_') === 0 ? '#' + checkboxId : '[id$="' + checkboxId + '"]';
			var $checkbox = $( selector );

			function update() {
				var isChecked = $checkbox.is(':checked');
				$.each( targetKeys, function( index, key ) {
					toggleRow( key, isChecked );
				});
			}

			if ( $checkbox.length ) {
				$checkbox.change( update );
				update();
			}
		}

		setupCheckboxToggle( 'woocommerce_drushfs_sameday_free_shipping', [
			'free_shipping_automat',
			'free_shipping_office',
			'free_shipping_address'
		]);

		setupCheckboxToggle( 'woocommerce_drushfs_sameday_fixed_shipping', [
			'fixed_shipping_automat',
			'fixed_shipping_office',
			'fixed_shipping_address'
		]);

		var $pricingSelect = $( '[id$="cenadostavka"]' );
		var $fixedCheckbox = $( '#woocommerce_drushfs_sameday_fixed_shipping' );
		var $freeCheckbox  = $( '#woocommerce_drushfs_sameday_free_shipping' );

		function updatePricingMethod() {
			var method = $pricingSelect.val();

			toggleRow( 'suma_nadbavka', method === 'nadbavka' );
			toggleRow( 'fileceni', method === 'fileprices' );

			if ( method === 'fixedprices' ) {
				if ( ! $fixedCheckbox.is(':checked') ) {
					$fixedCheckbox.prop( 'checked', true ).trigger( 'change' );
				}
				if ( $freeCheckbox.is(':checked') ) {
					$freeCheckbox.prop( 'checked', false ).trigger( 'change' );
				}
			} 
			else if ( method === 'freeshipping' ) {
				if ( ! $freeCheckbox.is(':checked') ) {
					$freeCheckbox.prop( 'checked', true ).trigger( 'change' );
				}
				if ( $fixedCheckbox.is(':checked') ) {
					$fixedCheckbox.prop( 'checked', false ).trigger( 'change' );
				}
			} 
			
		}

		if ( $pricingSelect.length ) {
			$pricingSelect.change( updatePricingMethod );
			var initialMethod = $pricingSelect.val();
			toggleRow( 'suma_nadbavka', initialMethod === 'nadbavka' );
			toggleRow( 'fileceni', initialMethod === 'fileprices' );
		}



		var $citySearch = $( '.sameday-city-search' );
		
		// Filter for modal context to avoid duplicates
		if ( $citySearch.length > 1 ) {
			$citySearch = $citySearch.filter(function() {
				return $(this).closest('.wc-backbone-modal-content').length > 0;
			});
		}

		if ( $citySearch.length ) {
			$citySearch.select2({
				width: '100%',
				placeholder: $citySearch.data('placeholder') || 'Search...',
				allowClear: true,
				ajax: {
					url: ajaxurl,
					dataType: 'json',
					delay: 250,
				data: function (params) {
					return {
						action: 'drushfs_search_cities',
						nonce: drushfs_admin.nonce,
						term: params.term
					};
				},
					processResults: function (data) {
						return data;
					},
					cache: true
				},
				minimumInputLength: 3
			});
		}

		var $officeSearch = $( '.sameday-point-search' );
		
		// Filter for modal context to avoid duplicates
		if ( $officeSearch.length > 1 ) {
			$officeSearch = $officeSearch.filter(function() {
				return $(this).closest('.wc-backbone-modal-content').length > 0;
			});
		}

		if ( $officeSearch.length ) {
			$officeSearch.select2({
				width: '100%',
				placeholder: $officeSearch.data('placeholder') || 'Search...',
				allowClear: true,
				ajax: {
					url: ajaxurl,
					dataType: 'json',
					delay: 250,
				data: function (params) {
					return {
						action: 'drushfs_search_offices',
						nonce: drushfs_admin.nonce,
						term: params.term,
						exclude_automats: '1'
					};
				},
					processResults: function (data) {
						return data;
					},
					cache: true
				},
				minimumInputLength: 3
			});
		}

	});

})( jQuery );
