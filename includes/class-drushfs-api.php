<?php
/**
 * Thin client for the Sameday (Deliveri Solutions) courier API.
 *
 * Everything the plugin needs is six endpoints. Sameday publishes a PHP SDK and
 * their own plugin vendors 101 files of it; we call the endpoints directly with
 * wp_remote_*, exactly as the Speedy plugin does, so there is nothing to keep in
 * sync with upstream.
 *
 * Shapes verified live against the demo environment on 16.09.2026:
 *  - every request body is form-urlencoded with PHP-style nesting
 *    (awbRecipient[name], parcels[0][weight]) — NOT JSON;
 *  - POST /api/awb answers 201, not 200;
 *  - DELETE /api/awb/{awb} answers 204 with an EMPTY body — never json_decode it;
 *  - the label lives at /api/awb/download/{awb}; the four-segment form the PDF
 *    documents (/download/{awb}/A4/PDF/inline) answers 400;
 *  - GET /api/awb/{awb} is forbidden for a client token: track through
 *    /api/client/status-sync, whose window must be under 7200 seconds.
 *
 * @package Drusoft_Shipping_For_Sameday
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sameday REST client.
 */
class Drushfs_Api {

	const HOST_LIVE = 'https://api.sameday.bg';
	const HOST_DEMO = 'https://sameday-api-bg.demo.zitec.com';

	/** Transient holding the auth token, per user + environment. */
	const TOKEN_TRANSIENT = 'drushfs_token_';

	/**
	 * Base URL for the configured environment.
	 *
	 * @param array $creds Credentials array, may carry 'sameday_env'.
	 * @return string
	 */
	public static function host( array $creds = array() ): string {
		$env = $creds['sameday_env'] ?? 'demo';
		return ( 'live' === $env ) ? self::HOST_LIVE : self::HOST_DEMO;
	}

	/**
	 * Exchange username and password for a token, cached until shortly before
	 * it expires. Sameday's tokens last about two weeks, so this is one call
	 * per fortnight rather than one per request.
	 *
	 * @param array $creds   Credentials.
	 * @param bool  $refresh Force a new token even if one is cached.
	 * @return string|WP_Error
	 */
	public static function token( array $creds, bool $refresh = false ) {
		$user = $creds['sameday_username'] ?? '';
		$pass = $creds['sameday_password'] ?? '';

		if ( '' === $user || '' === $pass ) {
			return new WP_Error( 'drushfs_no_credentials', __( 'Sameday username or password is missing.', 'drusoft-shipping-for-sameday' ) );
		}

		$key = self::TOKEN_TRANSIENT . md5( $user . '|' . self::host( $creds ) );

		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$response = wp_remote_post(
			self::host( $creds ) . '/api/authenticate',
			array(
				'headers' => array(
					'X-AUTH-USERNAME' => $user,
					'X-AUTH-PASSWORD' => $pass,
					'Content-Type'    => 'application/x-www-form-urlencoded',
				),
				'body'    => 'remember_me=true',
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['token'] ) ) {
			return new WP_Error(
				'drushfs_auth_failed',
				sprintf(
					/* translators: %d: HTTP status code returned by Sameday. */
					__( 'Sameday rejected the credentials (HTTP %d).', 'drusoft-shipping-for-sameday' ),
					$code
				)
			);
		}

		// Keep the token a little less time than Sameday does, so a request is
		// never made with one that expires mid-flight.
		$ttl = DAY_IN_SECONDS;
		if ( ! empty( $body['expire_at'] ) ) {
			$expires = strtotime( $body['expire_at'] );
			if ( $expires ) {
				$ttl = max( MINUTE_IN_SECONDS, $expires - time() - 10 * MINUTE_IN_SECONDS );
			}
		}
		set_transient( $key, $body['token'], $ttl );

		return $body['token'];
	}

	/**
	 * Perform an authenticated request, retrying once with a fresh token if the
	 * cached one has been invalidated on Sameday's side.
	 *
	 * @param array  $creds  Credentials.
	 * @param string $method HTTP method.
	 * @param string $path   Path beginning with a slash.
	 * @param array  $fields Body fields for POST, query args for GET.
	 * @param bool   $raw    Return the raw body (used for the label PDF).
	 * @return array|string|WP_Error Decoded body, raw string when $raw, or error.
	 */
	public static function request( array $creds, string $method, string $path, array $fields = array(), bool $raw = false ) {
		$token = self::token( $creds );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$result = self::dispatch( $creds, $token, $method, $path, $fields, $raw );

		if ( is_array( $result ) && isset( $result['__http'] ) && 401 === $result['__http'] ) {
			$token = self::token( $creds, true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$result = self::dispatch( $creds, $token, $method, $path, $fields, $raw );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result['__http'] ) ) {
			return new WP_Error(
				'drushfs_http_' . $result['__http'],
				self::error_text( $result ),
				$result
			);
		}

		return $result;
	}

	/**
	 * One HTTP round trip. Returns the decoded body on success, or an array
	 * carrying __http on an error status so the caller can decide to retry.
	 *
	 * @param array  $creds  Credentials.
	 * @param string $token  Auth token.
	 * @param string $method HTTP method.
	 * @param string $path   Path.
	 * @param array  $fields Fields.
	 * @param bool   $raw    Return raw body.
	 * @return array|string|WP_Error
	 */
	private static function dispatch( array $creds, string $token, string $method, string $path, array $fields, bool $raw ) {
		$url  = self::host( $creds ) . $path;
		$args = array(
			'method'  => $method,
			'headers' => array( 'X-AUTH-TOKEN' => $token ),
			'timeout' => 45,
		);

		if ( 'GET' === $method ) {
			if ( $fields ) {
				$url = add_query_arg( array_map( 'strval', $fields ), $url );
			}
		} elseif ( $fields ) {
			// PHP-style nesting is what the API expects; http_build_query
			// produces exactly awbRecipient[name]=… and parcels[0][weight]=…
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = http_build_query( $fields );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// 200 OK, 201 Created (POST /api/awb), 204 No Content (DELETE).
		if ( $code >= 200 && $code < 300 ) {
			if ( $raw ) {
				return $body;
			}
			if ( '' === trim( $body ) ) {
				return array();
			}
			$decoded = json_decode( $body, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		$decoded = json_decode( $body, true );
		return array(
			'__http' => $code,
			'__body' => is_array( $decoded ) ? $decoded : $body,
		);
	}

	/**
	 * Turn Sameday's validation envelope into one readable line.
	 *
	 * A failed field is buried as errors.children.<field>.errors[] — and for a
	 * nested object one level deeper still, which is why a missing recipient
	 * e-mail on a locker shipment otherwise reads as a bare "Validation Failed".
	 *
	 * @param array $result Dispatch result carrying __http and __body.
	 * @return string
	 */
	private static function error_text( array $result ): string {
		$body = $result['__body'] ?? '';

		if ( is_array( $body ) ) {
			$fields = array();
			$walk   = static function ( $children, string $prefix ) use ( &$walk, &$fields ) {
				foreach ( (array) $children as $name => $child ) {
					if ( ! is_array( $child ) ) {
						continue;
					}
					if ( ! empty( $child['errors'] ) ) {
						$fields[] = trim( $prefix . $name ) . ': ' . implode( ' ', (array) $child['errors'] );
					}
					if ( ! empty( $child['children'] ) ) {
						$walk( $child['children'], $prefix . $name . '.' );
					}
				}
			};

			if ( ! empty( $body['errors']['children'] ) ) {
				$walk( $body['errors']['children'], '' );
			}

			if ( $fields ) {
				return implode( '; ', $fields );
			}

			foreach ( array( 'message', 'detail', 'title' ) as $key ) {
				if ( ! empty( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
					return $body[ $key ];
				}
			}
			if ( ! empty( $body['error']['message'] ) ) {
				return (string) $body['error']['message'];
			}
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Sameday returned HTTP %d.', 'drusoft-shipping-for-sameday' ),
			(int) ( $result['__http'] ?? 0 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Endpoints
	 * ------------------------------------------------------------------ */

	/**
	 * Services enabled for this account (7 = 24H to address, 15 = Locker NextDay).
	 *
	 * @param array $creds Credentials.
	 * @return array|WP_Error
	 */
	public static function services( array $creds ) {
		return self::request( $creds, 'GET', '/api/client/services', array( 'countPerPage' => 100 ) );
	}

	/**
	 * Service id for a delivery type on this account.
	 *
	 * Ids are not stable across environments (PUDO is 48 on demo, 57 on
	 * production), but the serviceCode is: 24 = address, LN = locker,
	 * PP = PUDO. The code-to-id map is cached for a day per account.
	 *
	 * @param array  $creds Credentials.
	 * @param string $type  'address', 'easybox' or 'pudo'.
	 * @return int
	 */
	public static function service_for_type( array $creds, string $type ): int {
		$codes    = array( 'address' => '24', 'easybox' => 'LN', 'pudo' => 'PP' );
		$defaults = array( '24' => 7, 'LN' => 15, 'PP' => 57 );
		$code     = $codes[ $type ] ?? '24';

		$key = 'drushfs_services_' . md5( ( $creds['sameday_username'] ?? '' ) . '|' . self::host( $creds ) );
		$map = get_transient( $key );

		if ( ! is_array( $map ) ) {
			$map      = array();
			$response = self::services( $creds );
			if ( ! is_wp_error( $response ) ) {
				foreach ( (array) ( $response['data'] ?? array() ) as $service ) {
					if ( isset( $service['serviceCode'], $service['id'] ) ) {
						$map[ (string) $service['serviceCode'] ] = (int) $service['id'];
					}
				}
			}
			// An empty map after a failed call is retried in ten minutes, not a day.
			set_transient( $key, $map, $map ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		}

		return (int) ( $map[ $code ] ?? $defaults[ $code ] );
	}

	/**
	 * Id of an optional extra ("service tax") of a service, by its tax code.
	 *
	 * Ids differ per environment, per service and per package type (OPCG on
	 * 24H is 189336 on production and 61988 on demo for a standard package),
	 * while the tax code is stable. Cached for a day per account.
	 *
	 * @param array  $creds        Credentials.
	 * @param int    $service_id   Service id.
	 * @param string $tax_code     e.g. 'OPCG' — open the parcel before paying.
	 * @param int    $package_type 0 package, 1 envelope, 2 large.
	 * @return int 0 when the account does not have that extra on that service.
	 */
	public static function optional_tax_id( array $creds, int $service_id, string $tax_code, int $package_type = 0 ): int {
		$key = 'drushfs_service_taxes_' . md5( ( $creds['sameday_username'] ?? '' ) . '|' . self::host( $creds ) );
		$map = get_transient( $key );

		if ( ! is_array( $map ) ) {
			$map      = array();
			$response = self::services( $creds );
			if ( ! is_wp_error( $response ) ) {
				foreach ( (array) ( $response['data'] ?? array() ) as $service ) {
					foreach ( (array) ( $service['serviceOptionalTaxes'] ?? array() ) as $tax ) {
						if ( isset( $service['id'], $tax['taxCode'], $tax['id'] ) ) {
							$map[ (int) $service['id'] ][ (string) $tax['taxCode'] ][ (int) ( $tax['packageType'] ?? 0 ) ] = (int) $tax['id'];
						}
					}
				}
			}
			set_transient( $key, $map, $map ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		}

		return (int) ( $map[ $service_id ][ $tax_code ][ $package_type ] ?? 0 );
	}

	/**
	 * Our own warehouses; the pickup point id is required on every shipment.
	 *
	 * @param array $creds Credentials.
	 * @return array|WP_Error
	 */
	public static function pickup_points( array $creds ) {
		return self::request( $creds, 'GET', '/api/client/pickup-points', array( 'countPerPage' => 100 ) );
	}

	/**
	 * One page of easybox / PUDO locations. The id to send back as oohLastMile
	 * is the record's oohId, not its id.
	 *
	 * @param array  $creds   Credentials.
	 * @param int    $page    Page number, 1-based.
	 * @param int    $per     Records per page.
	 * @param string $country Country code.
	 * @return array|WP_Error
	 */
	public static function ooh_locations( array $creds, int $page = 1, int $per = 500, string $country = 'BG' ) {
		return self::request(
			$creds,
			'GET',
			'/api/client/ooh-locations',
			array(
				'countryCode'  => $country,
				// Without listingType the endpoint answers easybox only
				// (oohType 0). With it, SAMEDAY point / PUDO (oohType 1) is
				// included too, so the picker fills in by itself the day
				// Sameday opens real PUDO points in Bulgaria — on 16.09.2026
				// the only two in the list were demo rows with Romanian
				// addresses.
				'listingType'  => 1,
				'page'         => $page,
				'countPerPage' => $per,
			)
		);
	}

	/**
	 * Price for a shipment. Takes the same payload as create().
	 *
	 * @param array $creds   Credentials.
	 * @param array $payload Shipment payload.
	 * @return array|WP_Error {amount, currency, time}
	 */
	public static function estimate( array $creds, array $payload ) {
		return self::request( $creds, 'POST', '/api/awb/estimate-cost', $payload );
	}

	/**
	 * Create a waybill.
	 *
	 * @param array $creds   Credentials.
	 * @param array $payload Shipment payload.
	 * @return array|WP_Error {awbNumber, awbCost, parcels, pdfLink}
	 */
	public static function create_awb( array $creds, array $payload ) {
		return self::request( $creds, 'POST', '/api/awb', $payload );
	}

	/**
	 * Label PDF bytes for a waybill.
	 *
	 * @param array  $creds Credentials.
	 * @param string $awb   Waybill number.
	 * @return string|WP_Error
	 */
	public static function label( array $creds, string $awb ) {
		return self::request( $creds, 'GET', '/api/awb/download/' . rawurlencode( $awb ), array(), true );
	}

	/**
	 * Cancel a waybill. Answers 204 with no body.
	 *
	 * @param array  $creds Credentials.
	 * @param string $awb   Waybill number.
	 * @return array|WP_Error
	 */
	public static function cancel_awb( array $creds, string $awb ) {
		return self::request( $creds, 'DELETE', '/api/awb/' . rawurlencode( $awb ) );
	}

	/**
	 * Look a shipment up by OUR order reference, so the plugin never has to
	 * trust its own copy of the waybill number.
	 *
	 * @param array  $creds     Credentials.
	 * @param string $reference clientInternalReference sent at creation.
	 * @return array|WP_Error
	 */
	public static function awb_by_reference( array $creds, string $reference ) {
		return self::request( $creds, 'GET', '/api/client/awb/' . rawurlencode( $reference ) );
	}

	/**
	 * Status changes in a time window. Sameday refuses a window of 7200 seconds
	 * or more, so callers must page through longer periods in chunks.
	 *
	 * @param array $creds Credentials.
	 * @param int   $from  Unix timestamp, inclusive.
	 * @param int   $to    Unix timestamp, exclusive.
	 * @param int   $page  Page number.
	 * @return array|WP_Error
	 */
	public static function status_sync( array $creds, int $from, int $to, int $page = 1 ) {
		$to = min( $to, $from + 7000 );

		return self::request(
			$creds,
			'GET',
			'/api/client/status-sync',
			array(
				'startTimestamp' => $from,
				'endTimestamp'   => $to,
				'page'           => $page,
				'countPerPage'   => 100,
			)
		);
	}
}
