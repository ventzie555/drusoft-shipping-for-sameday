<?php
/**
 * Drusoft Speedy Shipping Method Class
 *
 * @copyright 2026 DRUSOFT LTD.
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Drushfs_Shipping_Method' ) ) {

	class Drushfs_Shipping_Method extends WC_Shipping_Method {

		/**
		 * Constructor for the shipping class
		 */
		public function __construct( $instance_id = 0 ) {
			// Fixes the "Missing parent constructor call" warning
			parent::__construct( $instance_id );

			$this->id                 = 'drushfs_speedy';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'Drusoft Shipping for Speedy', 'drusoft-shipping-for-sameday' );
			$this->method_description = __( 'Fresh, conflict-free Speedy delivery for Bulgaria.', 'drusoft-shipping-for-sameday' );

			$this->supports = array(
				'shipping-zones',
				'instance-settings',
				'instance-settings-modal',
			);

			$this->init();
		}

		/**
		 * Initialize settings and hooks
		 */
		public function init(): void {
			// Load the settings API
			$this->init_instance_settings();
			$this->init_form_fields();
			$this->init_settings();

			// Define user-set variables from instance settings
			$this->enabled = $this->get_instance_option( 'enabled', 'yes' );
			$this->title = $this->get_instance_option( 'title', __( 'Speedy Delivery', 'drusoft-shipping-for-sameday' ) );

			// Save settings in admin
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );

			// Save shipping data to order
			add_action( 'woocommerce_checkout_create_order', array( $this, 'save_shipping_data_to_order' ), 10, 2 );

			// Convert the stashed mixed-pickup meta into a real order note
			// once the order exists (idempotent — the meta is consumed).
			add_action( 'woocommerce_checkout_order_created', array( $this, 'add_mixed_pickup_note' ) );

			// An order placed without a delivery price is accepted — the sale
			// must not be lost — but the merchant has to hear about it.
			add_action( 'woocommerce_checkout_order_created', array( $this, 'flag_unpriced_shipping' ) );
		}

		/**
		 * Save Speedy session data to the order before it is created.
		 *
		 * @param WC_Order $order The order object being created.
         */
		public function save_shipping_data_to_order(WC_Order $order): void {
			// 1. Check if our shipping method is chosen
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
			$is_speedy      = false;

			if ( ! empty( $chosen_methods ) ) {
				foreach ( $chosen_methods as $method_id ) {
					if ( str_starts_with( $method_id, $this->id ) ) {
						$is_speedy = true;
						break;
					}
				}
			}

			if ( ! $is_speedy ) {
				return;
			}

			// 2. Get the selected service from session (set by our service selector)
			$chosen_service_id = WC()->session ? (int) WC()->session->get( 'drushfs_selected_service', 0 ) : 0;

			// 3. Try to get the service-specific session data first, fallback to general
			$session_data = null;
			if ( $chosen_service_id && WC()->session ) {
				$session_data = WC()->session->get( 'drushfs_shipping_data_' . $chosen_service_id );
			}
			if ( empty( $session_data ) ) {
				$session_data = WC()->session ? WC()->session->get( 'drushfs_shipping_data' ) : null;
			}

			if ( ! empty( $session_data ) ) {
				// Ensure the payload uses only the selected service
				if ( $chosen_service_id ) {
					$session_data['service']['serviceIds'] = [ $chosen_service_id ];
					$session_data['_selected_service_id']  = $chosen_service_id;
				}

				// Record the resolved pickup profile: the admin order screen can
				// override it before the waybill is generated.
				// This hook fires on EVERY instantiation of the method (including
				// the profile-less template instance 0), so resolve through the
				// CHOSEN method's zone instance and write idempotently.
				$chosen_instance = 0;
				foreach ( $chosen_methods as $method_id ) {
					if ( str_starts_with( $method_id, $this->id ) && str_contains( $method_id, ':' ) ) {
						$chosen_instance = (int) substr( strrchr( $method_id, ':' ), 1 );
						break;
					}
				}
				$resolver = ( $chosen_instance && $chosen_instance !== (int) $this->instance_id )
					? new self( $chosen_instance )
					: $this;
				$order_product_ids = [];
				foreach ( $order->get_items() as $item ) {
					$order_product_ids[] = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
				}
				if ( ! empty( $session_data['_split_parcels'] ) ) {
					// Split shipment: each parcel's sender is baked into its
					// payload — no single pickup profile applies, and the
					// waybill-time sender override must not fire.
					$order->update_meta_data( '_drushfs_pickup_profile', '' );
					$order->update_meta_data( '_drushfs_pickup_split', count( $session_data['_split_parcels'] ) + 1 );
				} else {
					$order->update_meta_data( '_drushfs_pickup_profile', $resolver->resolve_pickup_profile_key( $order_product_ids ) );
				}

			// Mixed basket (items assigned to different pickup points): the
			// order ships CONSOLIDATED from the resolved origin — flag it and
			// leave an order note so fulfilment knows goods must be gathered.
			$profiles   = $resolver->get_pickup_profiles();
			$item_lines = [];
			$item_keys  = [];
			foreach ( $order->get_items() as $item ) {
				$pid = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
				$key = get_post_meta( $pid, '_drushfs_pickup_profile', true );
				if ( ! $key && ( $parent = wp_get_post_parent_id( $pid ) ) ) {
					$key = get_post_meta( $parent, '_drushfs_pickup_profile', true );
				}
				if ( ! $key || ! isset( $profiles[ $key ] ) ) {
					$key = $resolver->pickup_opt( 'pickup_default_profile', 'default' );
					if ( ! isset( $profiles[ $key ] ) ) {
						$key = 'default';
					}
				}
				$item_keys[]  = $key;
				$item_lines[] = $item->get_name() . ' → ' . $profiles[ $key ]['label'];
			}
			if ( count( array_unique( $item_keys ) ) > 1 ) {
				$order->update_meta_data( '_drushfs_pickup_mixed', 1 );
				// The order has no ID yet inside checkout_create_order, so the
				// note text is stashed in meta and converted to a real order
				// note in add_mixed_pickup_note() once the order is saved.
				$order->update_meta_data( '_drushfs_pickup_note', implode( "\n", $item_lines ) );
			}

				// 4. Save to order meta
				// update_meta_data(), never add_meta_data(): this runs on every
				// save of the order, and add_ APPENDS a row each time instead
				// of replacing it. Same defect found and fixed in the Econt
				// plugin on 2026-08-20, where live orders had reached 21
				// identical copies of a single key.
				$order->update_meta_data( '_drushfs_order_data', $session_data );

				if ( isset( $session_data['recipient']['pickupOfficeId'] ) ) {
					$order->update_meta_data( '_drushfs_office_id', $session_data['recipient']['pickupOfficeId'] );
				}
			}

			// The city dropdown stores the Speedy siteId as its option value, so
			// WC writes a numeric string (e.g. "56784") into billing_city /
			// shipping_city. Convert the ID back to the proper city name AND
			// normalize the postcode from our cities table so the saved address
			// is human-readable everywhere (admin, invoice, CSV export). The
			// original siteId is stashed in meta; the waybill reads it from
			// _drushfs_order_data, so this conversion never affects the shipment.
			global $wpdb;
			foreach ( [ 'billing', 'shipping' ] as $addr ) {
				$city_getter = "get_{$addr}_city";
				$city_setter = "set_{$addr}_city";
				$pc_setter   = "set_{$addr}_postcode";
				$value       = (string) $order->$city_getter();
				if ( $value === '' || ! ctype_digit( $value ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT name, post_code FROM {$wpdb->prefix}drushfs_cities WHERE id = %d",
					(int) $value
				) );
				if ( ! $row || empty( $row->name ) ) {
					continue;
				}
				$order->update_meta_data( "_drushfs_{$addr}_city_id", (int) $value );
				$order->$city_setter( $row->name );
				if ( ! empty( $row->post_code ) ) {
					$order->$pc_setter( $row->post_code );
				}
			}
		}

		/**
		 * Leave an order note when the chosen Speedy rate reached the order
		 * with no price on it.
		 *
		 * The rate is tagged `missing_address` whenever the price call returned
		 * nothing, and checkout deliberately accepts such an order rather than
		 * refusing it: the merchant should get the sale. What was missing was any
		 * signal — the order arrives with a 0.00 shipping line and nothing in the
		 * order history says why, so it is found at waybill time or not at all
		 * (trisestri.bg order 807, 09.09.2026: accepted, ship_total 0.00, silent).
		 *
		 * Runs on woocommerce_checkout_order_created, after the order is saved, and
		 * reads the office through a fresh load: the object handed to this hook
		 * predates the drushfs_save_order_meta() rescue of the office from \$_POST.
		 * Idempotent via order meta, so several registered instances add it once.
		 *
		 * @param WC_Order $order The created order.
		 */
		public function flag_unpriced_shipping( $order ): void {
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh || $fresh->get_meta( '_drushfs_unpriced_noted' ) ) {
				return;
			}
			foreach ( $fresh->get_shipping_methods() as $item ) {
				if ( 'drushfs_speedy' !== $item->get_method_id() ) {
					continue;
				}
				if ( empty( $item->get_meta( 'missing_address' ) ) ) {
					return;
				}
				$type   = (string) $fresh->get_meta( '_drushfs_delivery_type' );
				$office = (string) $fresh->get_meta( '_drushfs_office_id' );
				if ( 'office' === $type ) {
					$where = 'до офис' . ( $office ? ' ' . $office : '' );
				} elseif ( 'automat' === $type ) {
					$where = 'до автомат' . ( $office ? ' ' . $office : '' );
				} else {
					$where = 'до адрес';
				}
				$fresh->add_order_note( sprintf(
					/* translators: 1: courier name, 2: delivery destination such as "до офис 6307" */
					__( 'Цената за доставка не беше изчислена при поръчката: %1$s не върна цена и клиентът не е таксуван за доставка (%2$s). Проверете цената, преди да изпратите пратката.', 'drusoft-shipping-for-sameday' ),
					'Спиди',
					$where
				) );
				$fresh->update_meta_data( '_drushfs_unpriced_noted', 1 );
				$fresh->save();
				return;
			}
		}

		/**
		 * Turn the stashed mixed-pickup meta into an order note. Runs on
		 * woocommerce_checkout_order_created (order saved, has an ID); the
		 * meta is consumed so multiple registered instances add it once.
		 *
		 * @param WC_Order $order The created order.
		 */
		public function add_mixed_pickup_note( $order ): void {
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$lines = (string) $order->get_meta( '_drushfs_pickup_note' );
			if ( '' === $lines ) {
				return;
			}
			$order->delete_meta_data( '_drushfs_pickup_note' );
			$order->save();
			$intro = $order->get_meta( '_drushfs_pickup_split' )
				? __( 'Mixed pickup points — ships as separate parcels:', 'drusoft-shipping-for-sameday' )
				: __( 'Mixed pickup points — consolidate before shipping:', 'drusoft-shipping-for-sameday' );
			$order->add_order_note( $intro . "\n" . $lines );
		}

		/**
		 * Processes and saves shipping method options in the admin area.
		 *
		 * Validates credentials against the Speedy API BEFORE saving.
		 * If invalid, only the basic fields are kept and the credentials are cleared.
		 */
		public function process_admin_options(): bool {

			// Get the posted data before saving
			$post_data = $this->get_post_data();
			$file_field_key = $this->get_field_key( 'fileceni' );

			// Check if a file path was submitted via the hidden input (AJAX upload)
			if ( ! empty( $post_data[ $file_field_key ] ) ) {
				// Sanitize the path
				$post_data[ $file_field_key ] = sanitize_text_field( $post_data[ $file_field_key ] );
			} 
			// Preserve existing value if nothing new is provided
			else {
				$existing_path = $this->get_option( 'fileceni' );
				if ( $existing_path ) {
					$post_data[ $file_field_key ] = $existing_path;
				}
			}
			
			// Update the post data with the resolved file path
			$this->set_post_data( $post_data );


			// Extract the credentials that the user just submitted
			$field_key_user = $this->get_field_key( 'speedy_username' );
			$field_key_pass = $this->get_field_key( 'speedy_password' );

			$new_username = isset( $post_data[ $field_key_user ] ) ? sanitize_text_field( $post_data[ $field_key_user ] ) : '';
			$new_password = isset( $post_data[ $field_key_pass ] ) ? sanitize_text_field( $post_data[ $field_key_pass ] ) : '';

			// Validate credentials BEFORE saving anything
			if ( $new_username && $new_password ) {
				$validation = $this->validate_speedy_credentials( $new_username, $new_password );

				if ( is_wp_error( $validation ) ) {
					$error_message = sprintf(
						/* translators: %s: error message from the Speedy API */
						__( 'Speedy API authentication failed: %s', 'drusoft-shipping-for-sameday' ),
						$validation->get_error_message()
					);

					$this->add_error(
						$error_message . ' ' . __( 'Credentials have been cleared.', 'drusoft-shipping-for-sameday' )
					);

					// Blank out the credentials in the post data so they save as empty
					$post_data[ $field_key_user ] = '';
					$post_data[ $field_key_pass ] = '';
					$this->set_post_data( $post_data );

					// Strip authenticated fields so their values don't get saved
					$basic_keys = [ 'section_api', 'enabled', 'title', 'speedy_username', 'speedy_password', 'info_msg' ];
					$this->instance_form_fields = array_intersect_key(
						$this->instance_form_fields,
						array_flip( $basic_keys )
					);

					// Now let parent save only the basic (credential) fields
					$saved = parent::process_admin_options();

					// The parent's init_instance_settings() loaded ALL old values from the DB.
					// Even though we stripped instance_form_fields, stale authenticated
					// field values remain in instance_settings and were written back.
					// Clean them out now by keeping only the basic keys.
					$option_key      = $this->get_instance_option_key();
					$saved_settings  = get_option( $option_key, [] );
					$clean_settings  = array_intersect_key( $saved_settings, array_flip( $basic_keys ) );
					update_option( $option_key, $clean_settings, 'yes' );

					$this->clear_speedy_cache();

					return $saved;
				}
			}

			// Credentials are valid (or empty) — save everything normally
			$saved = parent::process_admin_options();
			$this->clear_speedy_cache();

			if ( $saved && $this->get_option( 'speedy_username' ) && $this->get_option( 'speedy_password' ) ) {
				// Sync only when the tables are empty (first-time setup).
				// Cities and offices are the same for every Speedy account.
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$cities_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}drushfs_cities" );

				if ( 0 === $cities_count ) {
					if ( class_exists( 'Drushfs_Syncer' ) ) {
						Drushfs_Syncer::sync();
					}
				}

				// Ensure a recurring daily refresh is scheduled.
				if ( ! as_next_scheduled_action( 'drushfs_sync_locations_event' ) ) {
					if ( function_exists( 'as_schedule_recurring_action' ) ) {
						as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'drushfs_sync_locations_event' );
					}
				}
			}

			return $saved;
		}

		/**
		 * Validate Speedy API credentials by making a lightweight contract call.
		 *
		 * @param string $username Speedy username.
		 * @param string $password Speedy password.
		 *
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function validate_speedy_credentials( string $username, string $password ): bool|WP_Error {
			$body = wp_json_encode( [
				'userName' => $username,
				'password' => $password,
			] );

			$response = wp_remote_post( 'https://api.speedy.bg/v1/client/contract', [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => $body,
				'timeout' => 15,
			] );

			// Network / cURL error
			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'speedy_connection_error',
					$response->get_error_message()
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			// Speedy returns 401 for bad credentials
			if ( 401 === $code || ( isset( $data['error'] ) ) ) {
				$api_message = $data['error']['message']
					?? $data['error']
					?? __( 'Invalid username or password.', 'drusoft-shipping-for-sameday' );

				return new WP_Error( 'speedy_auth_failed', $api_message );
			}

			// Any non-200 response
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error(
					'speedy_api_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Unexpected API response (HTTP %d).', 'drusoft-shipping-for-sameday' ),
						$code
					)
				);
			}

			// If we got clients back, credentials are valid
			if ( isset( $data['clients'] ) && is_array( $data['clients'] ) ) {
				return true;
			}

			return new WP_Error(
				'speedy_unexpected_response',
				__( 'The API returned an unexpected response. Please check your credentials.', 'drusoft-shipping-for-sameday' )
			);
		}

        /**
         * Validate the sender_city field.
         *
         * Since the options are loaded via AJAX, the standard validation (checking against keys) fails.
         * We simply return the value (sanitized).
         *
         * @param string $_key
         * @param string $value
         * @return string
         */
		public function validate_sender_city_field( string $_key, string $value ): string {
			return sanitize_text_field( $value );
		}

        /**
         * Validate the sender_office field.
         *
         * Since the options are loaded via AJAX, the standard validation (checking against keys) fails.
         * We simply return the value (sanitized).
         *
         * @param string $_key
         * @param string $value
         * @return string
         */
		public function validate_sender_office_field( string $_key, string $value ): string {
			$office_id = absint( $value );
			if ( $office_id <= 0 ) {
				return sanitize_text_field( $value );
			}

			// Reject automats — they cannot be used as sender drop-off points.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT office_type, name FROM {$wpdb->prefix}drushfs_offices WHERE id = %d",
					$office_id
				)
			);
			if ( $row && drushfs_is_automat( $row->office_type, $row->name ) ) {
				WC_Admin_Settings::add_error(
					__( 'Automats (APT/APS) cannot be used as sender drop-off offices. Please select a regular Speedy office.', 'drusoft-shipping-for-sameday' )
				);
				return '';
			}

			return sanitize_text_field( $value );
		}

		/**
		 * Define the settings fields
		 */
		public function init_form_fields(): void {

			$this->instance_form_fields = array(
				// --- SECTION: CONNECTION ---
				'section_api' => [
					'title' => __( 'Speedy API Connection', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				],
				'enabled' => [
					'title'   => __( 'Module Status', 'drusoft-shipping-for-sameday' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable/Disable', 'drusoft-shipping-for-sameday' ),
					'default' => 'yes',
				],
				'title' => [
					'title'       => __( 'Method Title', 'drusoft-shipping-for-sameday' ),
					'type'        => 'text',
					'default'     => __( 'Speedy Delivery', 'drusoft-shipping-for-sameday' ),
					'desc_tip'    => true,
				],
				'speedy_username' => [
					'title' => __( 'Username', 'drusoft-shipping-for-sameday' ),
					'type'  => 'text'
				],
				'speedy_password' => [
					'title' => __( 'Password', 'drusoft-shipping-for-sameday' ),
					'type'  => 'password',
				],
			);

			// Only show advanced settings if API credentials are saved
			if ( $this->get_option('speedy_username') && $this->get_option('speedy_password') ) {
				$this->add_authenticated_fields();
			} else {
				$this->instance_form_fields['info_msg'] = [
					'type'        => 'title',
					'description' => __( 'Please save your credentials to unlock shipping options.', 'drusoft-shipping-for-sameday' ),
				];
			}
		}

		/**
		 * Fields that require a valid API connection.
		 * These are merged into the main form_fields array.
		 */
		/**
		 * One line for the settings screen: when the location tables were last
		 * refreshed from Speedy, and when the next refresh is due.
		 *
		 * @return string
		 */
		private function sync_status_text(): string {
			$last = (int) get_option( 'drushfs_last_sync', 0 );
			$next = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( 'drushfs_sync_locations_event' ) : false;
			if ( $last ) {
				$text = sprintf(
					/* translators: 1: date and time, 2: human-readable age such as "3 hours" */
					__( 'Last refreshed from Speedy: %1$s (%2$s ago).', 'drusoft-shipping-for-sameday' ),
					date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ),
					human_time_diff( $last )
				);
				if ( time() - $last > 3 * DAY_IN_SECONDS ) {
					$text .= ' <strong>' . __( 'That is more than 3 days — the daily refresh may not be running.', 'drusoft-shipping-for-sameday' ) . '</strong>';
				}
			} else {
				$text = '<strong>' . __( 'Never refreshed on a schedule yet.', 'drusoft-shipping-for-sameday' ) . '</strong>';
			}
			$text .= ' ' . ( $next
				? sprintf(
					/* translators: %s: human-readable time until the next run, such as "5 hours" */
					__( 'Next refresh in %s.', 'drusoft-shipping-for-sameday' ),
					human_time_diff( time(), (int) $next ) )
				: __( 'No refresh is scheduled — it will be scheduled automatically on the next page load.', 'drusoft-shipping-for-sameday' ) );
			return $text;
		}

		private function add_authenticated_fields(): void {
			
			// Read directly from saved settings to avoid WC looking up defaults
			// from form fields that haven't been defined yet (chicken-and-egg).
			if ( empty( $this->instance_settings ) ) {
				$this->init_instance_settings();
			}
			$current_city = (int) ( $this->instance_settings['sender_city'] ?? 0 );
			
			// Workaround: If a new city is posted, add it to options so validation passes
			// even if validate_sender_city_field is somehow bypassed or fails.
			$field_key = $this->get_field_key( 'sender_city' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in process_admin_options.
			if ( isset( $_POST[ $field_key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$posted_city = sanitize_text_field( wp_unslash( $_POST[ $field_key ] ) );
				if ( $posted_city ) {
					$current_city = $posted_city;
				}
			}

			$current_office = (int) ( $this->instance_settings['sender_office'] ?? 0 );
			$field_key_office = $this->get_field_key( 'sender_office' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in process_admin_options.
			if ( isset( $_POST[ $field_key_office ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$posted_office = sanitize_text_field( wp_unslash( $_POST[ $field_key_office ] ) );
				if ( $posted_office ) {
					$current_office = $posted_office;
				}
			}

			$authenticated = [

				// --- SECTION: LOCATION DATA ---
				// Read from an option the syncer writes on success, not from the
				// WC log: a log line can silently fail to write when the uploads
				// directory is owned by a different user than the one running the
				// sync (wp-cli vs php-fpm), and then an admin concludes the job is
				// dead when it is not. The option write is immune to that.
				'sync_status' => [
					'title'       => __( 'Offices & cities', 'drusoft-shipping-for-sameday' ),
					'type'        => 'title',
					'description' => $this->sync_status_text(),
				],

				// --- SECTION: SENDER DETAILS ---
				'section_sender' => [
					'title' => __( 'Sender Information', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				],
				'sender_id' => [
					'title'   => __( 'Sender (Object)', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'options' => $this->get_speedy_clients(),
				],
				'sender_name' => [
					'title' => __( 'Contact Person', 'drusoft-shipping-for-sameday' ),
					'type'  => 'text'
				],
				'sender_email' => [
					'title' => __( 'Email', 'drusoft-shipping-for-sameday' ),
					'type'  => 'email'
				],
				'sender_phone' => [
					'title' => __( 'Phone Number', 'drusoft-shipping-for-sameday' ),
					'type'  => 'text'
				],
				'sender_city' => [
					'title'   => __( 'City', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'class'   => 'speedy-city-search',
					'options' => [ $current_city => drushfs_get_city_name_by_id( $current_city ) ],
					'custom_attributes' => [
						'data-placeholder' => __( 'Search for a city...', 'drusoft-shipping-for-sameday' ),
					],
				],
				'sender_officeyesno' => [
					'title'   => __( 'Send from Office', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-sameday' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-sameday' ),
					],
				],
				'sender_office' => [
					'title'   => __( 'Shipping from Office', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'class'   => 'speedy-office-search',
					'options' => [ $current_office => drushfs_get_office_label_by_id( $current_office ) ],
					'custom_attributes' => [
						'data-placeholder' => __( 'Search for an office...', 'drusoft-shipping-for-sameday' ),
					],
				],
				'sender_time' => [
					'title'       => __( 'Working Day End Time', 'drusoft-shipping-for-sameday' ),
					'type'        => 'text',
					'placeholder' => '17:30',
					'description' => __( 'Format HH:MM', 'drusoft-shipping-for-sameday' ),
				],

				// --- SECTION: ADDITIONAL PICKUP POINTS (multi-origin) ---
				'section_pickup_profiles' => [
					'title'       => __( 'Additional Pickup Points', 'drusoft-shipping-for-sameday' ),
					'type'        => 'title',
					'description' => __( 'Optional. Define extra origins (warehouses, suppliers, offices) shipments can be picked up from. The sender above is always available as the "default" profile. Assign a pickup point per product on its Shipping tab, or programmatically via the drushfs_pickup_profile filter.', 'drusoft-shipping-for-sameday' ),
				],
				'pickup_profiles' => [
					'title'       => __( 'Pickup Points', 'drusoft-shipping-for-sameday' ),
					'type'        => 'textarea',
					'css'         => 'height:110px;font-family:monospace',
					'placeholder' => "warehouse2 | Склад Изток | object:123456789 | Иван Иванов | 0888123456\nsupplier1 | Доставчик X | office:14 | Петър Петров | 0888654321",
					'description' => __( 'One per line: key | label | office:&lt;office id&gt; OR object:&lt;Speedy client/object id&gt; | contact person | phone. Objects are pickup addresses registered on your Speedy contract (MySpeedy → Objects); offices are drop-off Speedy offices.', 'drusoft-shipping-for-sameday' ),
				],
				'pickup_default_profile' => [
					'title'       => __( 'Default Pickup Point', 'drusoft-shipping-for-sameday' ),
					'type'        => 'select',
					'default'     => 'default',
					'options'     => array_map(
						static fn( $p ) => $p['label'],
						$this->get_pickup_profiles()
					),
					'description' => __( 'Used for products without an explicit pickup-point assignment.', 'drusoft-shipping-for-sameday' ),
				],
				'pickup_mixed_policy' => [
					'title'       => __( 'Mixed-basket Handling', 'drusoft-shipping-for-sameday' ),
					'type'        => 'select',
					'default'     => 'consolidate',
					'options'     => [
						'consolidate' => __( 'Consolidate — one parcel from the default pickup point', 'drusoft-shipping-for-sameday' ),
						'split'       => __( 'Split — one parcel per pickup point (summed price, COD per parcel)', 'drusoft-shipping-for-sameday' ),
					],
					'description' => __( 'What happens when basket items are assigned to different pickup points. Split keeps one courier and delivery point; the customer sees the parcel count and the summed delivery price.', 'drusoft-shipping-for-sameday' ),
				],

				// --- SECTION: SHIPMENT SETTINGS ---
				'section_shipment' => [
					'title' => __( 'Shipment Settings', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				],
				'uslugi' => [
					'title'    => __( 'Active Services', 'drusoft-shipping-for-sameday' ),
					'type'     => 'multiselect',
					'options'  => $this->get_speedy_services(),
					'default'  => '505',
				],
			'opakovka' => [
				'title'       => __( 'Packaging', 'drusoft-shipping-for-sameday' ),
				'type'        => 'select',
				'default'     => 'BOX',
				'description' => __( 'Pallet is only available for pallet services and cannot be used with automat delivery. For automats the plugin will automatically fall back to Box.', 'drusoft-shipping-for-sameday' ),
				'options'     => [
					'ENVELOPE' => __( 'Envelope', 'drusoft-shipping-for-sameday' ),
					'BOX'      => __( 'Box', 'drusoft-shipping-for-sameday' ),
					'PALLET'   => __( 'Pallet', 'drusoft-shipping-for-sameday' ),
				],
			],
				'teglo' => [
					'title'   => __( 'Default Weight', 'drusoft-shipping-for-sameday' ),
					'type'    => 'number',
					'default' => '1'
				],
				'obqvena' => [
					'title'   => __( 'Declared Value', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-sameday' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-sameday' ),
					],
				],
				'chuplivost' => [
					'title'   => __( 'Fragile', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-sameday' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-sameday' ),
					],
				],
				'saturdayoption' => [
					'title'   => __( 'Saturday Delivery', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-sameday' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-sameday' ),
					],
				],
				'obpd_option' => [
					'title'       => __( 'Inspection before payment', 'drusoft-shipping-for-sameday' ),
					'type'        => 'select',
					'default'     => 'NONE',
					'description' => __( 'Let the recipient inspect (or test) the goods before paying the COD amount. Not available for APT (automat) deliveries. Return shipment on refusal is at your expense.', 'drusoft-shipping-for-sameday' ),
					'desc_tip'    => true,
					'options'     => [
						'NONE' => __( 'No', 'drusoft-shipping-for-sameday' ),
						'OPEN' => __( 'Inspection (open before payment)', 'drusoft-shipping-for-sameday' ),
						'TEST' => __( 'Inspection and test', 'drusoft-shipping-for-sameday' ),
					],
				],
				'special_requirements' => [
					'title'    => __( 'Special Requirements', 'drusoft-shipping-for-sameday' ),
					'type'     => 'select',
					'default'  => '0',
					'options'  => $this->get_speedy_special_requirements(),
				],

				// --- SECTION: PRICING & PAYMENT ---
				'section_pricing' => [
					'title' => __( 'Pricing & Payment', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				],
				'cenadostavka' => [
					'title'   => __( 'Pricing Method', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'speedycalculator',
					'options' => [
						'speedycalculator' => __( 'Speedy Calculator', 'drusoft-shipping-for-sameday' ),
						'fixedprices'      => __( 'Fixed Price', 'drusoft-shipping-for-sameday' ),
						'freeshipping'     => __( 'Free Shipping', 'drusoft-shipping-for-sameday' ),
						'fileprices'       => __( 'Custom Prices', 'drusoft-shipping-for-sameday' ),
						'nadbavka'         => __( 'Calculator + Surcharge', 'drusoft-shipping-for-sameday' ),
					],
				],
				'suma_nadbavka'          => [
					'title'        => __( 'Surcharge Amount', 'drusoft-shipping-for-sameday' ),
					'type'         => 'number',
					'custom_class' => 'suma-nadbavka',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fileceni'               => [
					'title'        => __( 'CSV Price File', 'drusoft-shipping-for-sameday' ),
					'type'         => 'text', // Changed to text for JS handling
					'class'        => 'speedy-file-input-wrapper', // Hook for JS
					'description'  => __( 'Path to CSV file with custom prices', 'drusoft-shipping-for-sameday' ),
				],
				'free_shipping' => [
					'title'       => __( 'Free Shipping', 'drusoft-shipping-for-sameday' ),
					'description' => __( 'Sum ABOVE the specified here activates free shipping to office/address. Explanation: If you want users to receive free shipping when reaching X amount - enter it with 0.01 less in the respective field. For example, for free shipping when reaching 100lv - enter 99.99 etc.', 'drusoft-shipping-for-sameday' ),
					'type'        => 'checkbox',
					'default'     => 'no'
				],
				'free_shipping_automat'  => [
					'title'        => __( 'Free Shipping to Automat > Amount', 'drusoft-shipping-for-sameday' ),
					'type'         => 'number',
					'custom_class' => 'free-shipping-automat',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'free_shipping_office'   => [
					'title'        => __( 'Free Shipping to Office > Amount', 'drusoft-shipping-for-sameday' ),
					'type'         => 'number',
					'custom_class' => 'free-shipping-office',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'free_shipping_address'  => [
					'title'        => __( 'Free Shipping to Address > Amount', 'drusoft-shipping-for-sameday' ),
					'type'         => 'number',
					'custom_class' => 'free-shipping-address',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fixed_shipping' => [
					'title'       => __( 'Fixed Shipping Price', 'drusoft-shipping-for-sameday' ),
					'description' => __( 'Enable fixed shipping price to office/address', 'drusoft-shipping-for-sameday' ),
					'type'        => 'checkbox',
					'default'     => 'no'
				],
				'fixed_shipping_automat' => [
					'title'        => __( 'Fixed Price to Automat', 'drusoft-shipping-for-sameday' ),
					'type'         => 'number',
					'custom_class' => 'fixed-shipping-automat',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fixed_shipping_office' => [
					'title' => __( 'Fixed Price to Office', 'drusoft-shipping-for-sameday' ),
					'type'  => 'number',
					'custom_class' => 'fixed-shipping-office',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fixed_shipping_address' => [
					'title' => __( 'Fixed Price to Address', 'drusoft-shipping-for-sameday' ),
					'type'  => 'number',
					'custom_class' => 'fixed-shipping-address',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'moneytransfer'          => [
					'title'   => __( 'Money Transfer Type', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'        => __( 'Cash on Delivery', 'drusoft-shipping-for-sameday' ),
						'YES'       => __( 'Postal Money Transfer', 'drusoft-shipping-for-sameday' ),
						'fiscal'    => __( 'Fiscal Receipt (Items)', 'drusoft-shipping-for-sameday' ),
						'fiscalone' => __( 'Fiscal Receipt (Groups)', 'drusoft-shipping-for-sameday' )
					],
				],
				'includeshippingprice'   => [
					'title'   => __( 'Include Shipping Price in COD', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-sameday' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-sameday' ),
					],
				],
				'administrative'         => [
					'title'   => __( 'Administrative Fee', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-sameday' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-sameday' ),
					],
				],

				// --- SECTION: WORKFLOW & OPTIONS ---
				'section_options' => [
					'title' => __( 'Workflow & Options', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				],
				'generate_waybill' => [
					'title'       => __( 'Automatic Waybill', 'drusoft-shipping-for-sameday' ),
					'description' => __( 'Automatically create waybill on order completion', 'drusoft-shipping-for-sameday' ),
					'type'        => 'checkbox',
					'default'     => 'no'
				],
				'printer'                => [
					'title'   => __( 'Label Printer', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-sameday' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-sameday' ),
					],
				],
				'additionalcopy'         => [
					'title'   => __( 'Additional Waybill Copy', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-sameday' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-sameday' ),
					],
				],
				'test_before_pay' => [
					'title'   => __( 'Options Before Payment', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'   => __( 'None', 'drusoft-shipping-for-sameday' ),
						'OPEN' => __( 'Open', 'drusoft-shipping-for-sameday' ),
						'TEST' => __( 'Test', 'drusoft-shipping-for-sameday' ),
					],
				],
				'testplatec'             => [
					'title'   => __( 'Return Shipment Payer (Test/Open)', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'SENDER',
					'options' => [
						'SENDER'    => __( 'Sender', 'drusoft-shipping-for-sameday' ),
						'RECIPIENT' => __( 'Recipient', 'drusoft-shipping-for-sameday' ),
					],
				],
				'autoclose'              => [
					'title'   => __( 'Auto Close Options at Automat', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-sameday' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-sameday' ),
					],
				],

				// --- SECTION: RETURNS & VOUCHERS ---
				'section_returns' => [
					'title' => __( 'Returns & Vouchers', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				],
				'vaucher' => [
					'title'   => __( 'Return Voucher', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-sameday' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-sameday' ),
					],
				],
				'vaucherpayer' => [
					'title'   => __( 'Return Payer', 'drusoft-shipping-for-sameday' ),
					'type'    => 'select',
					'default' => 'SENDER',
					'options' => [
						'SENDER'    => __( 'Sender', 'drusoft-shipping-for-sameday' ),
						'RECIPIENT' => __( 'Recipient', 'drusoft-shipping-for-sameday' ),
					],
				],
				'vaucherpayerdays'       => [
					'title'        => __( 'Voucher Validity (Days)', 'drusoft-shipping-for-sameday' ),
					'type'         => 'number',
				],
			];

			$this->instance_form_fields = array_merge( $this->instance_form_fields, $authenticated );
		}

		/**
		 * Fetch available clients/contracts from Speedy API
		 *
		 * @return array Associative array of [clientId => Client Details]
		 */
		private function get_speedy_clients(): array {
			$cache_key = 'drushfs_clients_cache_' . md5( $this->get_option( 'speedy_username' ) );
			$clients   = get_transient( $cache_key );

			// If cache exists, return it immediately
			if ( false !== $clients ) {
				return $clients;
			}

			$clients = [ '0' => __( '-- Select Client --', 'drusoft-shipping-for-sameday' ) ];

			$username = $this->get_option( 'speedy_username' );
			$password = $this->get_option( 'speedy_password' );

			if ( ! $username || ! $password ) {
				return $clients;
			}

			// Prepare API data
			$body = json_encode( [
				'userName' => $username,
				'password' => $password,
			] );

			$data = self::speedy_curl_post( 'https://api.speedy.bg/v1/client/contract', $body );

			if ( null === $data ) {
				return $clients;
			}

			// Process and Format Data
			if ( isset( $data['clients'] ) && is_array( $data['clients'] ) ) {
				foreach ( $data['clients'] as $client ) {
					$client_id   = $client['clientId'] ?? '';
					$client_name = $client['clientName'] ?? '';
					$object_name = $client['objectName'] ?? '';
					$address     = $client['address']['fullAddressString'] ?? '';

					$clients[ $client_id ] = sprintf(
					/* translators: 1: ID, 2: Name, 3: Object, 4: Address */
						__( 'ID: %1$s, %2$s, %3$s, Address: %4$s', 'drusoft-shipping-for-sameday' ),
						$client_id,
						$client_name,
						$object_name,
						$address
					);
				}

				// Cache the results for 24 hours to prevent repeated API hits
				set_transient( $cache_key, $clients, DAY_IN_SECONDS );
			}

			return $clients;
		}

		/**
		 * Get the first available office or automat ID for a specific city.
		 *
		 * @param int    $city_id
		 * @param string $type 'office' or 'automat'
		 * @return int Office ID or 0 if not found.
		 */
		public static function get_first_available_office( int $city_id, string $type ): int {
			global $wpdb;

			$like_automat = '%' . $wpdb->esc_like( 'АВТОМАТ' ) . '%';
			$like_aps     = '%' . $wpdb->esc_like( 'APS' ) . '%';
			$like_apt     = '%' . $wpdb->esc_like( 'APT' ) . '%';

			// Speedy types: APT/APS is automat, others are office.
			if ( 'automat' === $type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}drushfs_offices WHERE city_id = %d AND (office_type IN ('APT', 'APS') OR name LIKE %s OR name LIKE %s OR name LIKE %s) LIMIT 1",
						$city_id,
						$like_automat,
						$like_aps,
						$like_apt
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}drushfs_offices WHERE city_id = %d AND (office_type NOT IN ('APT', 'APS') AND name NOT LIKE %s AND name NOT LIKE %s AND name NOT LIKE %s) LIMIT 1",
					$city_id,
					$like_automat,
					$like_aps,
					$like_apt
				)
			);
		}

		/**
		 * Fetch available Speedy offices from API and sort them alphabetically
		 *
		 * @param string|null $username
		 * @param string|null $password
		 * @param string|null $term
		 * @return array Associative array of [officeId => "Name - Address"]
		 */
		public static function get_speedy_offices( ?string $username = null, ?string $password = null, ?string $term = null, bool $exclude_automats = false ): array {
			$offices = [ '0' => __( '-- Select Office --', 'drusoft-shipping-for-sameday' ) ];

			// Try to fetch from local DB first
			global $wpdb;

			// Check if table exists and has data
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'drushfs_offices' )
			);

			if ( $table_exists === $wpdb->prefix . 'drushfs_offices' ) {
				$db_offices = self::query_local_offices( $term, $exclude_automats );

				if ( ! empty( $db_offices ) ) {
					foreach ( $db_offices as $office ) {
						$offices[ $office->id ] = sprintf( '%s %s - %s', $office->id, $office->name, $office->address );
					}
					return $offices;
				}
			}

			// Fallback to API if DB is empty or no results found

			// If credentials are not provided, try to find them
			if ( ! $username || ! $password ) {
				$option_like = 'woocommerce_drushfs_speedy_%_settings';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
						$option_like
					)
				);

				if ( $rows ) {
					$settings = maybe_unserialize( $rows[0]->option_value );
					if ( is_array( $settings ) ) {
						$username = $settings['speedy_username'] ?? '';
						$password = $settings['speedy_password'] ?? '';
					}
				}
			}

			if ( ! $username || ! $password ) {
				return $offices;
			}

			// Prepare API data (Country ID 100 is Bulgaria)
			$body_data = [
				'userName'  => $username,
				'password'  => $password,
				'countryId' => 100,
			];

			if ( $term ) {
				$body_data['name'] = $term;
			}

			$body = json_encode( $body_data );

			$data = self::speedy_curl_post( 'https://api.speedy.bg/v1/location/office', $body );

			if ( null === $data ) {
				return $offices;
			}

			if ( isset( $data['offices'] ) && is_array( $data['offices'] ) ) {
				$temp_offices = [];

				foreach ( $data['offices'] as $office ) {
					$id      = $office['id'];
					$name    = $office['name'] ?? '';
					$address = $office['address']['fullAddressString'] ?? '';
					$type    = $office['type'] ?? '';

					// Skip automats when only real offices are requested
					if ( $exclude_automats && drushfs_is_automat( $type, $name ) ) {
						continue;
					}

					// We store a sort_key to handle Bulgarian (Cyrillic) sorting correctly
					$temp_offices[ $id ] = [
						'sort_key' => mb_strtoupper( $name, 'UTF-8' ),
						'label'    => sprintf( '%s %s - %s', $id, $name, $address )
					];
				}

				// Sort alphabetically by the name (sort_key)
				uasort( $temp_offices, function ( $a, $b ) {
					return strcmp( $a['sort_key'], $b['sort_key'] );
				} );

				// Flatten the array back to [ id => label ] for the WooCommerce select field
				foreach ( $temp_offices as $id => $office_data ) {
					$offices[ $id ] = $office_data['label'];
				}
			}

			return $offices;
		}

		/**
		 * Query the local drushfs_offices table with optional search term and automat exclusion.
		 *
		 * Separated into its own method so every query path uses $wpdb->prepare()
		 * with literal SQL — no interpolated variables.
		 *
		 * @param string|null $term             Search term (name/address LIKE).
		 * @param bool        $exclude_automats Whether to exclude APT/APS office types.
		 * @return array|object[]|null Database results.
		 */
		private static function query_local_offices( ?string $term, bool $exclude_automats ): ?array {
			global $wpdb;

			if ( $term && $exclude_automats ) {
				$like_term = '%' . $wpdb->esc_like( $term ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, name, address FROM {$wpdb->prefix}drushfs_offices WHERE (name LIKE %s OR address LIKE %s) AND office_type NOT IN ('APT','APS') ORDER BY name ASC LIMIT 50",
						$like_term,
						$like_term
					)
				);
			}

			if ( $term ) {
				$like_term = '%' . $wpdb->esc_like( $term ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, name, address FROM {$wpdb->prefix}drushfs_offices WHERE (name LIKE %s OR address LIKE %s) ORDER BY name ASC LIMIT 50",
						$like_term,
						$like_term
					)
				);
			}

			if ( $exclude_automats ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->get_results(
					"SELECT id, name, address FROM {$wpdb->prefix}drushfs_offices WHERE office_type NOT IN ('APT','APS') ORDER BY name ASC LIMIT 50"
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				"SELECT id, name, address FROM {$wpdb->prefix}drushfs_offices ORDER BY name ASC LIMIT 50"
			);
		}

		/**
		 * Fetch available Speedy services from API
		 *
		 * @return array Associative array of [serviceId => "ID - Service Name"]
		 */
		private function get_speedy_services(): array {
			$cache_key = 'drushfs_services_cache_' . md5( $this->get_option( 'speedy_username' ) );
			$services_list = get_transient( $cache_key );

			// Return cached data if available
			if ( false !== $services_list ) {
				return $services_list;
			}

			$services_list = [];

			$username = $this->get_option( 'speedy_username' );
			$password = $this->get_option( 'speedy_password' );

			if ( ! $username || ! $password ) {
				return $services_list;
			}

			// Prepare API data
			$body = json_encode( [
				'userName' => $username,
				'password' => $password,
			] );

			$data = self::speedy_curl_post( 'https://api.speedy.bg/v1/services', $body );

			if ( null === $data ) {
				return $services_list;
			}

			// Process and Format Data
			if ( isset( $data['services'] ) && is_array( $data['services'] ) ) {
				foreach ( $data['services'] as $service ) {
					if ( isset( $service['id'] ) && isset( $service['name'] ) ) {
						// Format as "505 - CITY COURIER"
						$services_list[ $service['id'] ] = sprintf( '%s - %s', $service['id'], $service['name'] );
					}
				}

				// Cache for 24 hours
				set_transient( $cache_key, $services_list, DAY_IN_SECONDS );
			}

			return $services_list;
		}

		/**
		 * Get a simple service ID → name map for rate labels.
		 *
		 * Reuses the cached service list from get_speedy_services() and strips
		 * the "505 - " prefix to return just the name portion.
		 *
		 * @return array<int, string> e.g. [505 => 'CITY COURIER', 515 => 'ECONOMY']
		 */
		private function get_speedy_service_names(): array {
			$services = $this->get_speedy_services();
			$names = [];
			foreach ( $services as $id => $label ) {
				// The label is formatted as "505 - CITY COURIER"
				$parts = explode( ' - ', $label, 2 );
				$names[ (int) $id ] = $parts[1] ?? $label;
			}
			return $names;
		}

		/**
		 * Fetch Special Requirements from Speedy API
		 */
		private function get_speedy_special_requirements(): array {
			$cache_key = 'drushfs_requirements_cache_' . md5( $this->get_option( 'speedy_username' ) );
			$requirements = get_transient( $cache_key );

			if ( false !== $requirements ) {
				return $requirements;
			}

			$requirements = [ '0' => __( '-- None --', 'drusoft-shipping-for-sameday' ) ];
			$username = $this->get_option( 'speedy_username' );
			$password = $this->get_option( 'speedy_password' );

			if ( ! $username || ! $password ) {
				return $requirements;
			}

			$body = json_encode( [
				'userName' => $username,
				'password' => $password,
			] );

			$data = self::speedy_curl_post( 'https://api.speedy.bg/v1/client/contract/info', $body );

			if ( null === $data ) {
				return $requirements;
			}

			if ( isset( $data['specialDeliveryRequirements']['requirements'] ) && is_array( $data['specialDeliveryRequirements']['requirements'] ) ) {
				foreach ( $data['specialDeliveryRequirements']['requirements'] as $req ) {
					// Use ID if available, otherwise use text as key (not ideal but fallback)
					$id = $req['id'] ?? $req['text'];
					$text = $req['text'] ?? $id;
					
					if ( $id && $text ) {
						$requirements[ $id ] = $text;
					}
				}
				set_transient( $cache_key, $requirements, DAY_IN_SECONDS );
			}

			return $requirements;
		}

		/**
		 * Clears all cached API data for this specific user
		 */
		private function clear_speedy_cache(): void {
			$user_hash = md5( $this->get_option( 'speedy_username' ) );

			delete_transient( 'drushfs_clients_cache_' . $user_hash );
			delete_transient( 'drushfs_offices_cache_' . $user_hash );
			delete_transient( 'drushfs_services_cache_' . $user_hash );
			delete_transient( 'drushfs_requirements_cache_' . $user_hash );
		}

		/**
		 * Calculate the shipping rate.
		 *
		 * Replicates the pricing logic from the legacy woocommerce-speedy-shipping
		 * plugin: ALWAYS calls the Speedy /v1/calculate API (for session storage
		 * and shipment validation), then overrides the displayed cost according
		 * to the configured pricing method (free, fixed, file, calculator, surcharge).
		 */
		public function calculate_shipping( $package = array() ): void {
			if ( $this->request_selects_other_shipping_method() ) {
				if ( function_exists( 'drushfs_clear_speedy_checkout_session' ) ) {
					drushfs_clear_speedy_checkout_session();
				}

				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => 0,
					'meta_data' => [ 'missing_address' => true ],
				] );
				return;
			}

			$username = $this->get_option( 'speedy_username' );
			$password = $this->get_option( 'speedy_password' );

			if ( ! $username || ! $password ) {
				// No credentials – still show the method with zero cost
				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => 0,
					'meta_data' => [ 'missing_address' => true ],
				] );
				return;
			}

			// --- 1. Parse checkout data ---
			$checkout       = $this->parse_checkout_post_data();
			$delivery_type  = $checkout['delivery_type']; // 'address', 'office', 'automat'
			$office_id      = $checkout['office_id'];
			$city_id        = $checkout['city_id'];
			$payment_method = $checkout['payment_method'];

			// Persist selection to session (but NOT the auto-picked office below)
			if ( WC()->session ) {
				WC()->session->set( 'drushfs_city_id', $city_id );
				WC()->session->set( 'drushfs_delivery_type', $delivery_type );
				if ( $office_id > 0 ) {
					WC()->session->set( 'drushfs_office_id', $office_id );
				}
			}

			// For office/automat delivery without a specific office chosen,
			// pick the first available one just for the price calculation.
			// Only on the cart page — on checkout the user selects an office.
			if ( is_cart() && in_array( $delivery_type, [ 'office', 'automat' ], true ) && empty( $office_id ) ) {
				$office_id = self::get_first_available_office( $city_id, $delivery_type );
			}

			// Final validation: if we still have no city ID for address delivery, try one last session lookup
			if ( 'address' === $delivery_type && empty( $city_id ) && WC()->session ) {
				$city_id = absint( WC()->session->get( 'drushfs_city_id', 0 ) );
			}

			// For address delivery, we need a city. 
			if ( 'address' === $delivery_type && empty( $city_id ) ) {
				// Clear stale session data
				if ( WC()->session ) {
					WC()->session->set( 'drushfs_service_options', [] );
					WC()->session->set( 'drushfs_shipping_cost', 0 );
				}
				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => 0,
					'meta_data' => [ 'missing_address' => true ],
				] );
				return;
			}

			// For office/automat delivery on checkout, we need the user to select an office.
			if ( ! is_cart() && in_array( $delivery_type, [ 'office', 'automat' ], true ) && empty( $office_id ) ) {
				if ( WC()->session ) {
					WC()->session->set( 'drushfs_service_options', [] );
					WC()->session->set( 'drushfs_shipping_cost', 0 );
				}
				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => 0,
					'meta_data' => [ 'missing_address' => true ],
				] );
				return;
			}

			// --- 2. Determine weight ---
			$order_weight = $this->resolve_weight( $package );

			// --- 3. Determine order total & subtotal ---
			// Note: during calculate_shipping(), WC_Cart_Totals has NOT yet
			// called calculate_totals(), so get_totals()['total'] is still 0
			// on the initial cart load.  Use get_subtotal() (items only) which
			// is always available because item subtotals are calculated first.
			$subtotal    = 0.0;
			$order_total = 0.0;
			if ( WC()->cart ) {
				$subtotal    = (float) WC()->cart->get_subtotal();
				$order_total = $subtotal;
			}

			$is_cod          = ( 'cod' === $payment_method );
			$cenadostavka    = $this->get_option( 'cenadostavka', 'speedycalculator' );

			// --- 4. Determine pricing overrides BEFORE building the API payload ---
			$is_free_shipping = false;
			$override_cost    = null; // null = use API price; float = use this value

			// 4a. Free shipping threshold (checkbox + per-type amount)
			if ( 'yes' === $this->get_option( 'free_shipping' ) ) {
				$threshold = $this->get_free_shipping_threshold( $delivery_type );
				if ( $threshold > 0 && $order_total > $threshold ) {
					$is_free_shipping = true;
					$override_cost    = 0.0;
				}
			}

			// 4b. Always-free pricing mode
			if ( 'freeshipping' === $cenadostavka ) {
				$is_free_shipping = true;
				$override_cost    = 0.0;
			}

			// 4c. Fixed shipping price
			$fixed_price = 0.0;
			$is_fixed    = false;
			if ( ! $is_free_shipping && ( 'fixedprices' === $cenadostavka || 'yes' === $this->get_option( 'fixed_shipping' ) ) ) {
				$fixed_price = $this->get_fixed_price( $delivery_type );
				if ( $fixed_price > 0 ) {
					$is_fixed      = true;
					$override_cost = $fixed_price;
				}
			}

			// 4d. CSV file prices
			$file_cost = false;
			$is_file   = false;
			if ( ! $is_free_shipping && ! $is_fixed && 'fileprices' === $cenadostavka ) {
				$file_cost = $this->get_csv_file_price( $delivery_type, $order_weight, $subtotal );
				if ( false !== $file_cost ) {
					$is_file       = true;
					$override_cost = $file_cost;
				}
			}

			// --- 5. Build the payload (always needed for session / waybill data) ---
			$payload = $this->build_api_calculate_payload(
				$delivery_type,
				$office_id,
				$city_id,
				$order_weight,
				$order_total,
				$subtotal,
				$is_cod,
				$payment_method,
				$is_free_shipping,
				$is_fixed ? $fixed_price : null,
				$is_file ? $file_cost : null
			);

			if ( empty( $payload ) ) {
				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => 0,
					'meta_data' => [ 'missing_address' => true ],
				] );
				return;
			}

			// --- 5b. Mixed basket with split policy: one parcel per pickup point.
			// The PRIMARY parcel replaces $payload (rebuilt for its own group);
			// SECONDARY parcels ride along in $payload['_split_parcels'] so they
			// flow through session -> order meta -> waybill untouched. The
			// customer keeps one courier/office choice; only the price (sum)
			// and the parcel count change.
			$split_parcels = [];
			$split_count   = 1;
			if ( 'split' === $this->pickup_opt( 'pickup_mixed_policy', 'consolidate' ) ) {
				$groups = $this->split_cart_groups();
				if ( count( $groups ) > 1 ) {
					$default_key = $this->pickup_opt( 'pickup_default_profile', 'default' );
					$primary_key = isset( $groups[ $default_key ] ) ? $default_key : array_key_first( $groups );
					$build_group = function ( string $key, array $g ) use ( $delivery_type, $office_id, $city_id, $is_cod, $payment_method, $is_free_shipping, $is_fixed, $fixed_price, $is_file, $file_cost ) {
						$this->pickup_key_override = $key;
						$p = $this->build_api_calculate_payload(
							$delivery_type, $office_id, $city_id,
							$g['weight'], $g['subtotal'], $g['subtotal'],
							$is_cod, $payment_method, $is_free_shipping,
							$is_fixed ? $fixed_price : null,
							$is_file ? $file_cost : null
						);
						$this->pickup_key_override = null;
						$p['content']['contents'] = mb_substr( implode( ', ', $g['names'] ), 0, 100 );
						return $p;
					};
					$payload = $build_group( $primary_key, $groups[ $primary_key ] );
					foreach ( $groups as $g_key => $g ) {
						if ( $g_key === $primary_key ) {
							continue;
						}
						$split_parcels[ $g_key ] = $build_group( $g_key, $g );
					}
					$split_count = count( $groups );
				}
			}

			// --- 6. Determine final cost ---
			// When we already know the price (free / fixed / file), skip the API
			// call entirely — it adds latency and its errors are irrelevant.
			// The API is only needed for 'speedycalculator' and 'nadbavka' modes.
			if ( null !== $override_cost ) {
				$final_cost = $override_cost;
				if ( $split_parcels ) {
					// Fixed/CSV override prices are per PARCEL — the customer
					// pays one price per shipment; free shipping stays free.
					if ( ! $is_free_shipping ) {
						$final_cost = $override_cost * $split_count;
					}
					$payload['_split_parcels'] = $split_parcels;
				}

				// Store cost in session
				if ( WC()->session ) {
					WC()->session->set( 'drushfs_shipping_cost', $final_cost );
					WC()->session->set( 'drushfs_shipping_data', $payload );
					// Clear service options — no service selector for override pricing
					WC()->session->set( 'drushfs_service_options', [] );
				}

				// Append "free shipping" hint to the label
				$label = $this->title;
				if ( $is_free_shipping ) {
					$label .= ' (' . __( 'Free shipping', 'drusoft-shipping-for-sameday' ) . ')';
				}
				if ( $split_parcels ) {
					/* translators: %d: number of parcels */
					$label .= ' (' . sprintf( _n( '%d parcel', '%d parcels', $split_count, 'drusoft-shipping-for-sameday' ), $split_count ) . ')';
				}

				$this->add_rate( [
					'id'    => $this->get_rate_id(),
					'label' => $label,
					'cost'  => number_format( $final_cost, 2, '.', '' ),
				] );
			} else {
				// ── Quick-path: if the session already has priced service options
				// (e.g. the user just switched the service dropdown) and the
				// underlying checkout inputs haven't changed, reuse them.
				$cached_service_options = WC()->session ? WC()->session->get( 'drushfs_service_options', [] ) : [];
				$selected_service       = WC()->session ? (int) WC()->session->get( 'drushfs_selected_service', 0 ) : 0;

				if ( ! empty( $cached_service_options ) && $selected_service && isset( $cached_service_options[ $selected_service ] ) ) {
					// Verify the cache is still relevant: same delivery type + city/office
					$cached_payload = WC()->session->get( 'drushfs_shipping_data' );
					$cache_hit      = false;

					if ( $cached_payload ) {
						// Determine the delivery type of the cached payload
						$cached_is_office  = ! empty( $cached_payload['recipient']['pickupOfficeId'] );
						$cached_is_address = ! empty( $cached_payload['recipient']['addressLocation']['siteId'] );

						// The delivery type must match between cached and current request
						if ( 'address' === $delivery_type && $cached_is_address ) {
							$cache_hit = ( (int) $cached_payload['recipient']['addressLocation']['siteId'] === $city_id );
						} elseif ( in_array( $delivery_type, [ 'office', 'automat' ], true ) && $cached_is_office ) {
							$cache_hit = ( (int) $cached_payload['recipient']['pickupOfficeId'] === $office_id );
						}
						// If delivery type doesn't match (e.g. cached=address, current=office),
						// $cache_hit stays false → forces a fresh API call.

						// The payment method affects courierServicePayer (SENDER vs RECIPIENT),
						// which changes the API price. Invalidate cache on payment method change.
						if ( $cache_hit ) {
							$cached_payer  = $cached_payload['payment']['courierServicePayer'] ?? '';
							$current_payer = $payload['payment']['courierServicePayer'] ?? '';
							if ( $cached_payer !== $current_payer ) {
								$cache_hit = false;
							}
						}
					}

					if ( $cache_hit ) {
						$active_cost = $cached_service_options[ $selected_service ]['cost'];

						$this->add_rate( [
							'id'        => $this->get_rate_id(),
							'label'     => $this->title,
							'cost'      => number_format( (float) $active_cost, 2, '.', '' ),
							'meta_data' => [ 'speedy_service_id' => $selected_service ],
						] );
						return;
					}
				}

				// We need the Speedy API price — call the calculator.
				$payload['userName'] = $username;
				$payload['password'] = $password;

				$response = $this->call_speedy_calculate_api( $payload );

				if ( is_wp_error( $response ) ) {
					$fallback = WC()->session ? WC()->session->get( 'drushfs_shipping_cost', 0 ) : 0;
					$this->add_rate( [
						'id'    => $this->get_rate_id(),
						'label' => $this->title,
						'cost'  => number_format( (float) $fallback, 2, '.', '' ),
					] );
					return;
				}

				// Check for top-level API error
				if ( ! empty( $response['error'] ) ) {
					$fallback = WC()->session ? WC()->session->get( 'drushfs_shipping_cost', 0 ) : 0;
					$this->add_rate( [
						'id'    => $this->get_rate_id(),
						'label' => $this->title,
						'cost'  => number_format( (float) $fallback, 2, '.', '' ),
					] );
					return;
				}

				// Collect all successful calculations
				$successful_calcs = [];
				if ( ! empty( $response['calculations'] ) ) {
					foreach ( $response['calculations'] as $calc ) {
						if ( isset( $calc['price']['total'] ) && isset( $calc['serviceId'] ) ) {
							$successful_calcs[] = $calc;
						}
					}
				}

				if ( empty( $successful_calcs ) ) {
					$fallback = WC()->session ? WC()->session->get( 'drushfs_shipping_cost', 0 ) : 0;
					$this->add_rate( [
						'id'    => $this->get_rate_id(),
						'label' => $this->title,
						'cost'  => number_format( (float) $fallback, 2, '.', '' ),
					] );
					return;
				}

				// Secondary parcels: price each against the same services and
				// sum per service. A parcel that fails to price makes the whole
				// split unpriceable — fall back to the last known cost.
				$split_costs = [];
				foreach ( $split_parcels as $sp_key => $sp_payload ) {
					$sp_payload['userName'] = $username;
					$sp_payload['password'] = $password;
					$sp_response = $this->call_speedy_calculate_api( $sp_payload );
					if ( is_wp_error( $sp_response ) || ! empty( $sp_response['error'] ) || empty( $sp_response['calculations'] ) ) {
						$fallback = WC()->session ? WC()->session->get( 'drushfs_shipping_cost', 0 ) : 0;
						$this->add_rate( [
							'id'    => $this->get_rate_id(),
							'label' => $this->title,
							'cost'  => number_format( (float) $fallback, 2, '.', '' ),
						] );
						return;
					}
					foreach ( $sp_response['calculations'] as $sp_calc ) {
						if ( isset( $sp_calc['price']['total'], $sp_calc['serviceId'] ) ) {
							$sid = (int) $sp_calc['serviceId'];
							$split_costs[ $sid ] = ( $split_costs[ $sid ] ?? 0 ) + (float) $sp_calc['price']['total'];
						}
					}
				}

				// Build a service ID → name map from the settings
				$service_names = $this->get_speedy_service_names();

				// Remove credentials before storing in session
				$session_payload = $payload;
				unset( $session_payload['userName'], $session_payload['password'] );

				// ── Build service options for the frontend ──
				// Determine which service to select by default.
				// If the user already picked one (stored in session), keep it.
				$selected_service = WC()->session ? (int) WC()->session->get( 'drushfs_selected_service', 0 ) : 0;

				$service_options = [];
				$first_service_id = null;
				$default_cost     = null;

				foreach ( $successful_calcs as $calc ) {
					$service_id = (int) $calc['serviceId'];
					$api_total  = (float) $calc['price']['total'];

					$final_cost = $api_total + ( $split_costs[ $service_id ] ?? 0 );
					if ( 'nadbavka' === $cenadostavka ) {
						$final_cost += (float) $this->get_option( 'suma_nadbavka', 0 );
					}

					$service_name = $service_names[ $service_id ]
						?? ( __( 'Service', 'drusoft-shipping-for-sameday' ) . ' ' . $service_id );

					$service_options[ $service_id ] = [
						'id'   => $service_id,
						'name' => $service_name,
						'cost' => round( $final_cost, 2 ),
					];

					if ( null === $first_service_id ) {
						$first_service_id = $service_id;
						$default_cost     = $final_cost;
					}

					// Store a per-service payload in session for waybill generation
					$svc_payload = $session_payload;
					$svc_payload['service']['serviceIds'] = [ $service_id ];
					$svc_payload['_selected_service_id']  = $service_id;
					if ( $split_parcels ) {
						$svc_payload['_split_parcels'] = array_map(
							static function ( $sp ) use ( $service_id ) {
								unset( $sp['userName'], $sp['password'] );
								$sp['service']['serviceIds'] = [ $service_id ];
								return $sp;
							},
							$split_parcels
						);
					}

					if ( WC()->session ) {
						WC()->session->set( 'drushfs_shipping_data_' . $service_id, $svc_payload );
					}
				}

				// If the previously-selected service is still available, keep it
				if ( $selected_service && isset( $service_options[ $selected_service ] ) ) {
					$active_service_id = $selected_service;
					$active_cost       = $service_options[ $selected_service ]['cost'];
				} else {
					$active_service_id = $first_service_id;
					$active_cost       = $default_cost;
				}

				// Store in session so JS can render a service selector
				if ( WC()->session ) {
					WC()->session->set( 'drushfs_service_options', $service_options );
					WC()->session->set( 'drushfs_shipping_cost', $active_cost );
					WC()->session->set( 'drushfs_selected_service', $active_service_id );

					$active_payload = $session_payload;
					$active_payload['service']['serviceIds'] = [ $active_service_id ];
					$active_payload['_selected_service_id']  = $active_service_id;
					if ( $split_parcels ) {
						$active_payload['_split_parcels'] = array_map(
							static function ( $sp ) use ( $active_service_id ) {
								unset( $sp['userName'], $sp['password'] );
								$sp['service']['serviceIds'] = [ $active_service_id ];
								return $sp;
							},
							$split_parcels
						);
					}
					WC()->session->set( 'drushfs_shipping_data', $active_payload );
				}

				// Always add a SINGLE rate – service selection is handled in our custom UI
				$rate_label = $this->title;
				if ( $split_parcels ) {
					/* translators: %d: number of parcels */
					$rate_label .= ' (' . sprintf( _n( '%d parcel', '%d parcels', $split_count, 'drusoft-shipping-for-sameday' ), $split_count ) . ')';
				}
				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $rate_label,
					'cost'      => number_format( $active_cost, 2, '.', '' ),
					'meta_data' => [ 'speedy_service_id' => $active_service_id ],
				] );
			}
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Parse checkout POST data
		 * ─────────────────────────────────────────────────── */

		/**
		 * Extract Speedy-specific fields from the checkout AJAX request.
		 *
		 * WooCommerce sends checkout form data as a URL-encoded string in
		 * $_POST['post_data'] during AJAX shipping updates. During final
		 * order placement, fields are at the top level of $_POST.
		 *
		 * @return array {
		 *     @type string $delivery_type  'address', 'office', or 'automat'
		 *     @type int    $office_id      Speedy office/automat ID (0 if none)
		 *     @type int    $city_id        Speedy city (site) ID (0 if none)
		 *     @type string $payment_method WC payment method slug
		 * }
		 */
		private function parse_checkout_post_data(): array {
			$data = [];

			// During AJAX updates, form data comes as URL-encoded string
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- post_data is a URL-encoded string; individual values are sanitized below.
			if ( ! empty( $_POST['post_data'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				parse_str( wp_unslash( $_POST['post_data'] ), $data );
			}

			// During final checkout, or if post_data is missing, check top-level $_POST
			// phpcs:ignore WordPress.Security.NonceVerification
			$merged = array_merge( $data, $_POST );

			// post_data is present on checkout AJAX (update_order_review), absent on cart form submit.
			// phpcs:ignore WordPress.Security.NonceVerification
			$has_post_data = ! empty( $_POST['post_data'] );

			// Determine which address context to use (billing or shipping)
			$ship_to_different = ! empty( $merged['ship_to_different_address'] );
			$context = $ship_to_different ? 'shipping' : 'billing';

			// Delivery Type
			$delivery_type = sanitize_text_field( $merged['speedy_delivery_type'] ?? '' );
			if ( empty( $delivery_type ) && WC()->session ) {
				$delivery_type = WC()->session->get( 'drushfs_delivery_type', 'address' );
			}
			if ( empty( $delivery_type ) ) {
				$delivery_type = 'address';
			}

			// Office ID
			$office_id = absint( $merged['speedy_office_id'] ?? 0 );
			// On the cart page, fall back to session (set by get_first_available_office).
			// On checkout, do NOT fall back — the user must select an office explicitly.
			if ( $office_id === 0 && WC()->session && ! $has_post_data ) {
				$office_id = absint( WC()->session->get( 'drushfs_office_id', 0 ) );
			}

			// City ID: the checkout.js replaces the city input with a <select> whose
			// name is "{context}_city" and value is the Speedy siteId.
			$city_id = 0;

			// 1. Try checkout fields (Speedy IDs are numbers)
			if ( ! empty( $merged[ $context . '_city' ] ) && is_numeric( $merged[ $context . '_city' ] ) ) {
				$city_id = absint( $merged[ $context . '_city' ] );
			} elseif ( ! empty( $merged['billing_city'] ) && is_numeric( $merged['billing_city'] ) ) {
				$city_id = absint( $merged['billing_city'] );
			}
			// 2. Try cart calculator fields
			elseif ( ! empty( $merged['calc_shipping_city'] ) && is_numeric( $merged['calc_shipping_city'] ) ) {
				$city_id = absint( $merged['calc_shipping_city'] );
			}
			// 3. Try customer session as last resort
			elseif ( WC()->session ) {
				$city_id = absint( WC()->session->get( 'drushfs_city_id', 0 ) );
				if ( ! $city_id && WC()->customer ) {
					$session_city = WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city();
					if ( is_numeric( $session_city ) ) {
						$city_id = absint( $session_city );
					}
				}
			}

			return [
				'delivery_type'  => $delivery_type,
				'office_id'      => $office_id,
				'city_id'        => $city_id,
				'payment_method' => sanitize_text_field( $merged['payment_method'] ?? '' ),
			];
		}

		/**
		 * Check whether the current checkout request explicitly selected a non-Speedy method.
		 *
		 * @return bool True when shipping_method is present and none of its values are Speedy.
		 */
		private function request_selects_other_shipping_method(): bool {
			$data = [];

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- post_data is a URL-encoded checkout payload; values are sanitized below.
			if ( ! empty( $_POST['post_data'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				parse_str( wp_unslash( $_POST['post_data'] ), $data );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$merged = array_merge( $data, $_POST );
			$methods = $merged['shipping_method'] ?? [];

			if ( ! is_array( $methods ) ) {
				$methods = [ $methods ];
			}

			$methods = array_filter( array_map( 'sanitize_text_field', $methods ) );
			if ( empty( $methods ) ) {
				return false;
			}

			foreach ( $methods as $method_id ) {
				if ( str_starts_with( $method_id, $this->id ) ) {
					return false;
				}
			}

			return true;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Resolve package weight
		 * ─────────────────────────────────────────────────── */

		/**
		 * Determine the shipment weight.
		 *
		 * If the admin set a fixed "teglo" (weight) setting, use it.
		 * Otherwise compute from the cart contents, falling back to 1 kg.
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return float Weight in kg.
		 */
		private function resolve_weight( array $package ): float {
			$fixed_weight = $this->get_option( 'teglo' );

			// If teglo is non-empty AND not zero, use it as a fixed override
			if ( '' !== $fixed_weight && '0' !== $fixed_weight && (float) $fixed_weight > 0 ) {
				return (float) $fixed_weight;
			}

			// Calculate from cart/package contents
			$weight = 0.0;
			if ( ! empty( $package['contents'] ) ) {
				foreach ( $package['contents'] as $item ) {
					$product = $item['data'];
					$weight += (float) $product->get_weight() * $item['quantity'];
				}
			}

			// Fallback: use WC cart weight (covers edge cases)
			if ( $weight <= 0 && WC()->cart ) {
				$weight = WC()->cart->get_cart_contents_weight();
			}

			// Final fallback: 1 kg
			return $weight > 0 ? $weight : 1.0;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Free shipping threshold for delivery type
		 * ─────────────────────────────────────────────────── */

		/**
		 * Get the free shipping threshold for a specific delivery type.
		 *
		 * @param string $delivery_type 'address', 'office', or 'automat'
		 * @return float Threshold amount (0 = disabled).
		 */
		private function get_free_shipping_threshold( string $delivery_type ): float {
			$map = [
				'office'  => 'free_shipping_office',
				'automat' => 'free_shipping_automat',
				'address' => 'free_shipping_address',
			];

			$key = $map[ $delivery_type ] ?? '';
			return $key ? (float) $this->get_option( $key, 0 ) : 0.0;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Fixed price for delivery type
		 * ─────────────────────────────────────────────────── */

		/**
		 * Get the fixed shipping price for a specific delivery type.
		 *
		 * @param string $delivery_type 'address', 'office', or 'automat'
		 * @return float Fixed price (0 = not set).
		 */
		private function get_fixed_price( string $delivery_type ): float {
			$map = [
				'office'  => 'fixed_shipping_office',
				'automat' => 'fixed_shipping_automat',
				'address' => 'fixed_shipping_address',
			];

			$key = $map[ $delivery_type ] ?? '';
			return $key ? (float) $this->get_option( $key, 0 ) : 0.0;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: CSV file-based pricing
		 * ─────────────────────────────────────────────────── */

		/**
		 * Look up shipping cost from a user-uploaded CSV price file.
		 *
		 * CSV format (header + data rows):
		 *   service_id, take_from_office, weight, order_total, price
		 *
		 * take_from_office: 0 = address, 1 = office, 2 = automat
		 *
		 * @param string $delivery_type 'address', 'office', or 'automat'
		 * @param float  $weight        Shipment weight.
		 * @param float  $subtotal      Cart subtotal.
		 * @return float|false Price from CSV, or false if no match found.
		 */
		private function get_csv_file_price( string $delivery_type, float $weight, float $subtotal ): float|false {
			// Try instance option first, then legacy global option
			$file_path = $this->get_option( 'fileceni' );
			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				$file_path = get_option( 'drushfs_fileceni_path' );
			}

			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				return false;
			}

			// Map delivery type to CSV column value
			$type_map = [
				'address' => 0,
				'office'  => 1,
				'automat' => 2,
			];
			$take_from_office = $type_map[ $delivery_type ] ?? 0;

			// Use WP_Filesystem to read the file.
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! WP_Filesystem() || ! $wp_filesystem ) {
				return false;
			}

			$csv_content = $wp_filesystem->get_contents( $file_path );
			if ( false === $csv_content || empty( $csv_content ) ) {
				return false;
			}

			$lines = explode( "\n", $csv_content );
			if ( empty( $lines ) ) {
				return false;
			}

			// Skip header row
			array_shift( $lines );

			$best_fit_price       = null;
			$best_fit_order_total = null;

			foreach ( $lines as $line ) {
				if ( empty( trim( $line ) ) ) {
					continue;
				}

				$row = str_getcsv( $line, ',', '"', '' );
				if ( count( $row ) < 5 ) {
					continue;
				}

				list( $_csv_service_id, $csv_take_from_office, $csv_weight, $csv_order_total, $csv_price ) = $row;

				if (
					(int) $csv_take_from_office === $take_from_office &&
					$weight <= (float) $csv_weight &&
					$subtotal <= (float) $csv_order_total
				) {
					// Pick the row with the smallest csv_order_total that still covers this order
					if ( null === $best_fit_order_total || (float) $csv_order_total < $best_fit_order_total ) {
						$best_fit_order_total = (float) $csv_order_total;
						$best_fit_price       = (float) $csv_price;
					}
				}
			}

			return $best_fit_price;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Build full Speedy /v1/calculate payload
		 * ─────────────────────────────────────────────────── */

		/**
		 * Assemble the Speedy API calculate request body.
		 *
		 * The old plugin always sends the full payload to the API even when
		 * the final cost will be overridden (free / fixed / file). When a
		 * pricing override is active, the courier payer is forced to SENDER
		 * and the COD amount is adjusted to subtotal + shipping cost.
		 *
		 * @param string     $delivery_type    'address', 'office', or 'automat'
		 * @param int        $office_id        Speedy office/automat ID.
		 * @param int        $city_id          Speedy city (site) ID.
		 * @param float      $order_weight     Shipment weight in kg.
		 * @param float      $order_total      Order total minus shipping.
		 * @param float      $subtotal         Cart subtotal.
		 * @param bool       $is_cod           Whether payment is Cash on Delivery.
		 * @param string     $_payment_method  WC payment method slug.
		 * @param bool       $is_free          Whether free shipping is active.
		 * @param float|null $fixed_price      Fixed shipping cost (null = not active).
		 * @param float|null $file_price       CSV file shipping cost (null = not active).
		 * @return array The API request payload (without credentials).
		 */

		/**
		 * When set, pickup_sender_block()/payload building use THIS profile
		 * instead of resolving from the cart (used for per-parcel payloads).
		 *
		 * @var string|null
		 */
		public $pickup_key_override = null;

		/**
		 * Group the current cart by resolved pickup profile.
		 *
		 * @return array<string, array{ids:int[],weight:float,subtotal:float,names:string[]}>
		 */
		public function split_cart_groups(): array {
			$groups = [];
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return $groups;
			}
			$profiles = $this->get_pickup_profiles();
			$default  = $this->pickup_opt( 'pickup_default_profile', 'default' );
			if ( ! isset( $profiles[ $default ] ) ) {
				$default = 'default';
			}
			$fallback_weight = (float) apply_filters( 'drushfs_default_item_weight', 0.5 );
			foreach ( WC()->cart->get_cart() as $item ) {
				$pid = (int) ( $item['variation_id'] ?: $item['product_id'] );
				$key = get_post_meta( $pid, '_drushfs_pickup_profile', true );
				if ( ! $key && ( $parent = wp_get_post_parent_id( $pid ) ) ) {
					$key = get_post_meta( $parent, '_drushfs_pickup_profile', true );
				}
				if ( ! $key || ! isset( $profiles[ $key ] ) ) {
					$key = $default;
				}
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = [ 'ids' => [], 'weight' => 0.0, 'subtotal' => 0.0, 'names' => [] ];
				}
				$product = $item['data'];
				$qty     = (int) $item['quantity'];
				$w       = $product ? (float) $product->get_weight() : 0.0;
				if ( $w <= 0 ) {
					$w = $fallback_weight;
				}
				$groups[ $key ]['ids'][]     = $pid;
				$groups[ $key ]['weight']   += $w * $qty;
				$groups[ $key ]['subtotal'] += (float) ( $item['line_subtotal'] ?? 0 ) + (float) ( $item['line_subtotal_tax'] ?? 0 );
				$groups[ $key ]['names'][]   = ( $product ? $product->get_name() : ( 'product#' . $pid ) ) . ' x' . $qty;
			}
			return $groups;
		}

		/**
		 * Instance-settings reader safe to call while form fields are still
		 * being built (get_option() can't resolve instance settings for keys
		 * not yet registered — same chicken-and-egg as sender_city above).
		 */
		public function pickup_opt( string $key, string $fallback = '' ): string {
			if ( empty( $this->instance_settings ) ) {
				$this->init_instance_settings();
			}
			$v = $this->instance_settings[ $key ] ?? '';
			return '' === $v || null === $v ? $fallback : (string) $v;
		}

		/**
		 * All configured pickup profiles, keyed by profile key.
		 *
		 * 'default' is always present and mirrors the legacy sender_* settings,
		 * so existing installs behave exactly as before.
		 *
		 * @return array<string, array{key:string,label:string,mode:string,id:int,contact:string,phone:string}>
		 */
		public function get_pickup_profiles(): array {
			$profiles = [
				'default' => [
					'key'     => 'default',
					'label'   => __( 'Default sender', 'drusoft-shipping-for-sameday' ),
					'mode'    => 'YES' === $this->get_option( 'sender_officeyesno' ) ? 'office' : 'object',
					'id'      => 'YES' === $this->get_option( 'sender_officeyesno' )
						? (int) $this->get_option( 'sender_office' )
						: (int) $this->get_option( 'sender_id' ),
					'contact' => (string) $this->get_option( 'sender_name' ),
					'phone'   => (string) $this->get_option( 'sender_phone' ),
				],
			];
			foreach ( preg_split( '/\r\n|\r|\n/', $this->pickup_opt( 'pickup_profiles' ) ) as $line ) {
				$parts = array_map( 'trim', explode( '|', $line ) );
				if ( count( $parts ) < 3 || '' === $parts[0] ) {
					continue;
				}
				if ( ! preg_match( '/^(office|object):(\d+)$/', $parts[2], $m ) ) {
					continue;
				}
				$key = sanitize_key( $parts[0] );
				$profiles[ $key ] = [
					'key'     => $key,
					'label'   => $parts[1] ?: $key,
					'mode'    => $m[1],
					'id'      => (int) $m[2],
					'contact' => $parts[3] ?? '',
					'phone'   => $parts[4] ?? '',
				];
			}
			return $profiles;
		}

		/**
		 * Resolve which pickup profile applies for a set of products.
		 *
		 * Per-product assignment (_drushfs_pickup_profile meta) wins when all
		 * items agree; disagreements fall back to the mixed-cart policy. The
		 * drushfs_pickup_profile filter has the final word (used for
		 * programmatic routing, e.g. supplier-based dropshipping).
		 *
		 * @param int[] $product_ids Product/variation IDs in the cart or order.
		 */
		public function resolve_pickup_profile_key( array $product_ids ): string {
			$profiles = $this->get_pickup_profiles();
			$default  = $this->pickup_opt( 'pickup_default_profile', 'default' );
			if ( ! isset( $profiles[ $default ] ) ) {
				$default = 'default';
			}
			$assigned = [];
			foreach ( $product_ids as $pid ) {
				$key = get_post_meta( $pid, '_drushfs_pickup_profile', true );
				if ( ! $key && ( $parent = wp_get_post_parent_id( $pid ) ) ) {
					$key = get_post_meta( $parent, '_drushfs_pickup_profile', true );
				}
				$assigned[] = ( $key && isset( $profiles[ $key ] ) ) ? $key : $default;
			}
			// Mixed assignments consolidate through the default pickup point
			// (split-into-parcels is the planned alternative policy).
			$unique   = array_values( array_unique( $assigned ) );
			$resolved = 1 === count( $unique ) ? $unique[0] : $default;
			/**
			 * Filter the resolved pickup profile key.
			 *
			 * @param string $resolved    Resolved profile key.
			 * @param int[]  $product_ids Products being shipped.
			 * @param array  $profiles    All configured profiles.
			 */
			$resolved = apply_filters( 'drushfs_pickup_profile', $resolved, $product_ids, $profiles );
			return isset( $profiles[ $resolved ] ) ? $resolved : 'default';
		}

		/**
		 * Sender block for the Speedy calculate/shipment APIs from a profile.
		 * Offices become dropoffOfficeId; objects (contract pickup addresses
		 * registered in MySpeedy) become clientId — Speedy resolves the address
		 * and prices the address-pickup surcharge itself.
		 */
		public function pickup_sender_block( string $profile_key ): array {
			$profiles = $this->get_pickup_profiles();
			$p        = $profiles[ $profile_key ] ?? $profiles['default'];
			$sender   = [];
			if ( 'office' === $p['mode'] && $p['id'] > 0 ) {
				$sender['dropoffOfficeId'] = $p['id'];
				// office drop-offs still bill to our contract client when set
				$client = (int) $this->get_option( 'sender_id' );
				if ( $client > 0 ) {
					$sender['clientId'] = $client;
				}
			} elseif ( $p['id'] > 0 ) {
				$sender['clientId'] = $p['id'];
			}
			if ( '' !== $p['contact'] ) {
				$sender['contactName'] = $p['contact'];
			}
			if ( '' !== $p['phone'] ) {
				$sender['phone1'] = [ 'number' => $p['phone'] ];
			}
			$email = $this->get_option( 'sender_email' );
			if ( ! empty( $email ) ) {
				$sender['email'] = $email;
			}
			return $sender;
		}

		private function build_api_calculate_payload(
			string $delivery_type,
			int $office_id,
			int $city_id,
			float $order_weight,
			float $order_total,
			float $subtotal,
			bool $is_cod,
			string $_payment_method,
			bool $is_free = false,
			?float $fixed_price = null,
			?float $file_price = null
		): array {

			$has_price_override = $is_free || null !== $fixed_price || null !== $file_price;

			// ── Sender ──
			// The Speedy /v1/calculate API requires sender data to determine
			// pricing based on the sender's contract and location. The origin is
			// resolved per cart via pickup profiles ('default' = legacy sender
			// settings), so multi-origin quotes price the correct pickup point.
			$cart_product_ids = [];
			if ( function_exists( 'WC' ) && WC()->cart ) {
				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$cart_product_ids[] = (int) ( $cart_item['variation_id'] ?: $cart_item['product_id'] );
				}
			}
			$pickup_key = null !== $this->pickup_key_override
				? $this->pickup_key_override
				: $this->resolve_pickup_profile_key( $cart_product_ids );
			$sender     = $this->pickup_sender_block( $pickup_key );

			// If no sender data was set, send empty object so JSON encodes as {}
			if ( empty( $sender ) ) {
				$sender = new stdClass();
			}

			// ── Recipient ──
			$recipient = [
				'privatePerson' => true,
			];

			if ( in_array( $delivery_type, [ 'office', 'automat' ], true ) ) {
				$recipient['pickupOfficeId'] = $office_id;
			} else {
				$recipient['addressLocation'] = [
					'siteId' => $city_id,
				];
			}

			// ── Service ──
			$service_ids = $this->get_option( 'uslugi', [] );
			if ( ! is_array( $service_ids ) ) {
				$service_ids = array_map( 'intval', explode( ',', $service_ids ) );
			} else {
				$service_ids = array_map( 'intval', $service_ids );
			}
			// Remove zeroes and ensure we have at least one service
			$service_ids = array_values( array_filter( $service_ids ) );
			if ( empty( $service_ids ) ) {
				$service_ids = [ 505 ]; // Default: Standard courier
			}

			$service = [
				'autoAdjustPickupDate' => true,
				'serviceIds'           => $service_ids,
			];

			if ( 'YES' === $this->get_option( 'saturdayoption' ) ) {
				$service['saturdayDelivery'] = true;
			}

			// ── Content ──
			$content = [
				'parcelsCount' => 1,
				'totalWeight'  => $order_weight,
			];

			// ── Payment ──
			$include_shipping_in_cod = ( 'YES' === $this->get_option( 'includeshippingprice' ) );

			// Payer is RECIPIENT only when ALL of:
			//   1. COD payment
			//   2. includeshippingprice is not YES
			//   3. cenadostavka is 'speedycalculator' or 'nadbavka' (pure API pricing)
			// For all other pricing modes (fileprices, fixedprices, freeshipping)
			// or when includeshippingprice is YES, use SENDER.
			//
			// This runs while quoting, before anyone knows what the cart will
			// actually be charged for shipping, so it can only ever be a guess.
			// The binding decision is re-made against the saved order in
			// DRUSHFS_Waybill_Generator::resolve_courier_service_payer().
			$cenadostavka_mode = $this->get_option( 'cenadostavka', 'speedycalculator' );
			$api_pricing_modes = [ 'speedycalculator', 'nadbavka' ];


			if ( $is_cod && ! $include_shipping_in_cod && in_array( $cenadostavka_mode, $api_pricing_modes, true ) && ! $has_price_override ) {
				$payment = [ 'courierServicePayer' => 'RECIPIENT' ];
			} else {
				$payment = [ 'courierServicePayer' => 'SENDER' ];
			}


			if ( 'YES' === $this->get_option( 'administrative' ) ) {
				$payment['administrativeFee'] = true;
			}

			// ── Additional Services ──

			if ( $is_cod ) {
				$money_transfer  = $this->get_option( 'moneytransfer', 'NO' );
				$processing_type = ( 'YES' === $money_transfer ) ? 'POSTAL_MONEY_TRANSFER' : 'CASH';

				// COD amount: use the items subtotal as the base.
				// During calculate_shipping(), WC()->cart->get_totals()['total']
				// is still 0 because WC_Cart_Totals hasn't called calculate_totals()
				// yet (shipping is calculated first). The subtotal (items only) is the
				// always available and is the correct base for COD.
				$cod_amount = $subtotal;

				// For fixed/file pricing with COD, the old plugin sets cod.amount
				// to subtotal + shipping cost so the courier collects the right total.
				if ( null !== $fixed_price ) {
					$cod_amount = $subtotal + $fixed_price;
				} elseif ( null !== $file_price ) {
					$cod_amount = $subtotal + $file_price;
				} elseif ( 'nadbavka' === $this->get_option( 'cenadostavka' ) ) {
					// Surcharge mode: add surcharge to COD amount
					$cod_amount += (float) $this->get_option( 'suma_nadbavka', 0 );
				}

				$service['additionalServices']['cod'] = [
					'amount'                  => $cod_amount,
					'processingType'          => $processing_type,
					'ignoreIfNotApplicable'   => true,
				];

				if ( $include_shipping_in_cod ) {
					$service['additionalServices']['cod']['includeShippingPrice'] = true;
				}

				// Declared Value (old plugin only adds this inside COD branch)
				if ( 'YES' === $this->get_option( 'obqvena' ) ) {
					$service['additionalServices']['declaredValue'] = [
						'amount'                => $order_total,
						'fragile'               => ( 'YES' === $this->get_option( 'chuplivost' ) ),
						'ignoreIfNotApplicable' => true,
					];
				}

				// Return Voucher (old plugin only adds inside COD branch)
				if ( 'YES' === $this->get_option( 'vaucher' ) ) {
					$voucher = [
						'serviceId'             => 505,
						'payer'                 => $this->get_option( 'vaucherpayer', 'SENDER' ),
						'ignoreIfNotApplicable' => true,
					];

					$validity = $this->get_option( 'vaucherpayerdays' );
					if ( ! empty( $validity ) ) {
						$voucher['validityPeriod'] = (int) $validity;
					}

					$service['additionalServices']['returns']['returnVoucher'] = $voucher;
				}

				// Special Delivery Requirements (old plugin only adds inside COD branch)
				$special_req = $this->get_option( 'special_requirements' );
				if ( ! empty( $special_req ) && '0' !== $special_req ) {
					$service['additionalServices']['specialDeliveryId'] = $special_req;
				}
			}
			// Non-COD: don't include COD in additional services at all
			// (the official Speedy API example omits COD entirely when not applicable)

			// OBPD (Test Before Pay / Open Before Pay)
			// Old plugin adds this regardless of COD status
			$obpd_option = $this->get_option( 'test_before_pay', 'NO' );
			$autoclose   = $this->get_option( 'autoclose', 'NO' );

			if (
				in_array( $obpd_option, [ 'OPEN', 'TEST' ], true ) &&
				( 'automat' !== $delivery_type || 'NO' === $autoclose )
			) {
				$service['additionalServices']['obpd'] = [
					'option'                  => $obpd_option,
					'returnShipmentServiceId' => 505,
					'returnShipmentPayer'     => ( 'OPEN' === $obpd_option )
						? ( $this->get_option( 'testplatec' ) ?: 'SENDER' )
						: 'SENDER',
					'ignoreIfNotApplicable'   => true,
				];
			}

			// ── Fiscal Receipt Items (for fiscal / fiscalone modes) ──
			$money_transfer_mode = $this->get_option( 'moneytransfer', 'NO' );
			if ( $is_cod && in_array( $money_transfer_mode, [ 'fiscal', 'fiscalone' ], true ) && WC()->cart ) {
				$cenadostavka_mode = $this->get_option( 'cenadostavka', 'speedycalculator' );
				$fiscal_items      = $this->build_fiscal_receipt_items( $money_transfer_mode, $cenadostavka_mode, $service['additionalServices']['cod']['amount'] ?? 0 );
				if ( ! empty( $fiscal_items ) ) {
					$service['additionalServices']['cod']['fiscalReceiptItems'] = $fiscal_items;
				}
			}

			return [
				'sender'    => $sender,
				'recipient' => $recipient,
				'service'   => $service,
				'content'   => $content,
				'payment'   => $payment,
			];
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Build fiscal receipt items
		 * ─────────────────────────────────────────────────── */

		/**
		 * Generate fiscal receipt line items for the Speedy COD fiscal receipt.
		 *
		 * When fixedprices or fileprices is active and cod_amount exceeds the
		 * product total, a separate "Доставка" (Delivery) line is appended
		 * to cover the shipping portion – matching the old plugin behavior.
		 *
		 * @param string $mode            'fiscal' (per-item) or 'fiscalone' (per VAT group).
		 * @param string $cenadostavka    Pricing mode setting.
		 * @param float  $cod_amount      Total COD amount from the payload.
		 * @return array Array of fiscal receipt items.
		 */
		private function build_fiscal_receipt_items( string $mode, string $cenadostavka = '', float $cod_amount = 0.0 ): array {
			$fiscal_items            = [];
			$products_total_with_vat = 0.0;

			if ( 'fiscal' === $mode ) {
				// Per-product line items
				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$product     = $cart_item['data'];
					$qty         = $cart_item['quantity'];
					$name        = $product->get_name() . ' (x' . $qty . ')';
					$description = mb_substr( $name, 0, 50 );

					$vat_info = $this->resolve_vat_info( $product );

					$price_incl_vat = (float) $product->get_price();
					$price_excl_vat = $vat_info['rate'] > 0
						? $price_incl_vat / ( 1 + $vat_info['rate'] )
						: $price_incl_vat;

					$line_with_vat = round( $price_incl_vat * $qty, 2 );
					$line_ex_vat   = round( $price_excl_vat * $qty, 2 );

					$products_total_with_vat += $line_with_vat;

					$fiscal_items[] = [
						'description'   => $description,
						'vatGroup'      => $vat_info['group'],
						'amount'        => $line_ex_vat,
						'amountWithVat' => $line_with_vat,
					];
				}
			} elseif ( 'fiscalone' === $mode ) {
				// Grouped by VAT class
				$groups = [
					'А' => [ 'ex' => 0, 'in' => 0 ], // 0%
					'Г' => [ 'ex' => 0, 'in' => 0 ], // 9%
					'Б' => [ 'ex' => 0, 'in' => 0 ], // 20%
				];

				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$product = $cart_item['data'];
					$qty     = $cart_item['quantity'];

					$vat_info       = $this->resolve_vat_info( $product );
					$price_incl_vat = (float) $product->get_price();
					$price_excl_vat = $vat_info['rate'] > 0
						? $price_incl_vat / ( 1 + $vat_info['rate'] )
						: $price_incl_vat;

					$groups[ $vat_info['group'] ]['ex'] += $price_excl_vat * $qty;
					$groups[ $vat_info['group'] ]['in'] += $price_incl_vat * $qty;
				}

				foreach ( $groups as $group => $sum ) {
					if ( $sum['in'] <= 0 ) {
						continue;
					}

					$products_total_with_vat += $sum['in'];

					$fiscal_items[] = [
						/* translators: %s: VAT group identifier (e.g. А, Б, Г) */
						'description'   => sprintf( __( 'Products from order (group %s)', 'drusoft-shipping-for-sameday' ), $group ),
						'vatGroup'      => $group,
						'amount'        => round( $sum['ex'], 2 ),
						'amountWithVat' => round( $sum['in'], 2 ),
					];
				}
			}

			// Append a "Доставка" (Delivery) line when fixed/file pricing is used
			// and the COD amount exceeds the product total.
			if (
				in_array( $cenadostavka, [ 'fixedprices', 'fileprices' ], true ) &&
				$cod_amount > 0
			) {
				$shipping_amount_with_vat = max( 0, $cod_amount - $products_total_with_vat );

				if ( $shipping_amount_with_vat > 0 ) {
					$shipping_vat_rate      = 0.20;
					$shipping_amount_ex_vat = $shipping_amount_with_vat / ( 1 + $shipping_vat_rate );

					$fiscal_items[] = [
						'description'   => __( 'Delivery', 'drusoft-shipping-for-sameday' ),
						'vatGroup'      => 'Б',
						'amount'        => round( $shipping_amount_ex_vat, 2 ),
						'amountWithVat' => round( $shipping_amount_with_vat, 2 ),
					];
				}
			}

			return $fiscal_items;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Resolve VAT info for a product
		 * ─────────────────────────────────────────────────── */

		/**
		 * Get the Bulgarian VAT group and rate for a WC product.
		 *
		 * @param WC_Product $product
		 * @return array { @type string $group, @type float $rate }
		 */
		private function resolve_vat_info(WC_Product $product ): array {
			$tax_class = $product->get_tax_class();

			if ( 'zero-rate' === $tax_class ) {
				return [ 'group' => 'А', 'rate' => 0.00 ];
			}

			if ( 'reduced-rate' === $tax_class ) {
				return [ 'group' => 'Г', 'rate' => 0.09 ];
			}

			// Standard rate (default)
			return [ 'group' => 'Б', 'rate' => 0.20 ];
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Call Speedy /v1/calculate API
		 * ─────────────────────────────────────────────────── */

		/**
		 * Send a calculate request to the Speedy API.
		 *
		 * Uses wp_remote_post() instead of raw cURL for WordPress best practices.
		 *
		 * @param array $payload Full request body including credentials.
		 * @return array|WP_Error Decoded API response or WP_Error.
		 */
		private function call_speedy_calculate_api( array $payload ): array|WP_Error {

			$body = $this->do_speedy_calculate_request( $payload );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			// If the combined call succeeded with at least one calculation, return it.
			$has_success = false;
			if ( ! empty( $body['calculations'] ) ) {
				foreach ( $body['calculations'] as $calc ) {
					if ( isset( $calc['price']['total'] ) ) {
						$has_success = true;
						break;
					}
				}
			}

			if ( $has_success ) {
				return $body;
			}

			// Combined call failed (top-level error or all calculations failed).
			// If multiple serviceIds were sent, retry each one individually.
			$service_ids = $payload['service']['serviceIds'] ?? [];
			if ( count( $service_ids ) <= 1 ) {
				return $body; // Single service – nothing more to try.
			}


			$merged_calculations = [];
			foreach ( $service_ids as $sid ) {
				$single_payload = $payload;
				$single_payload['service']['serviceIds'] = [ $sid ];

				$single_body = $this->do_speedy_calculate_request( $single_payload );

				if ( is_wp_error( $single_body ) ) {
					continue;
				}

				if ( ! empty( $single_body['calculations'] ) ) {
					foreach ( $single_body['calculations'] as $calc ) {
						$merged_calculations[] = $calc;
					}
				}
			}

			if ( ! empty( $merged_calculations ) ) {
				return [ 'calculations' => $merged_calculations ];
			}

			// All individual calls failed too – return the original combined response.
			return $body;
		}

		/**
		 * Execute a JSON POST request to a Speedy API endpoint.
		 *
		 * @param string $url  Full API endpoint URL.
		 * @param string $body JSON-encoded request body.
		 * @return array|null  Decoded response or null on error.
		 */
		private static function speedy_curl_post( string $url, string $body ): ?array {
			$response = wp_remote_post( $url, [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => $body,
				'timeout' => 15,
			] );

			if ( is_wp_error( $response ) ) {
				return null;
			}

			$response_body = wp_remote_retrieve_body( $response );
			if ( empty( $response_body ) ) {
				return null;
			}

			return json_decode( $response_body, true );
		}

		/**
		 * Execute a single Speedy /v1/calculate HTTP request.
		 *
		 * @param array $payload Full request body including credentials.
		 * @return array|WP_Error Decoded API response or WP_Error.
		 */
		private function do_speedy_calculate_request( array $payload ): array|WP_Error {
			$response = wp_remote_post( 'https://api.speedy.bg/v1/calculate', [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code < 200 || $code >= 300 ) {
				$api_msg = $body['error']['message'] ?? "HTTP $code";
				return new WP_Error( 'speedy_api_error', $api_msg );
			}

			return $body;
		}
	}
}
