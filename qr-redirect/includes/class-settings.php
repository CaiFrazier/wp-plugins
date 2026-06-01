<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Settings management for CF QR Redirect.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_Settings {

	/**
	 * Per-request cache for the merged settings array. Cleared on update().
	 * Avoids paying the get_option() cost three or four times per redirect
	 * (build_utm_url, render_intermediate, save_meta all read several keys).
	 */
	private static $cache = null;

	/**
	 * Per-request cache for resolve_ga4_id(), which otherwise traverses
	 * settings → constant → Site Kit option on every call.
	 */
	private static $ga4_cache = null;

	/**
	 * Default values for every setting key.
	 */
	public static function defaults() {
		return array(
			'ga4_measurement_id'     => '',
			// Optional numeric GA4 property ID. When set, edit-screen "Open in GA4" links
			// land in the right property instead of the GA4 home screen.
			'ga4_property_id'        => '',
			'default_analytics_mode' => 'utm',
			'default_utm_source'     => 'qr',
			'default_utm_medium'     => 'qr-code',
			'redirect_status'        => 302,
			'short_code_length'      => 8,
		);
	}

	/**
	 * Read all settings, merged with defaults. Memoized for the request — every
	 * caller gets the same array without re-hitting the options table.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = get_option( CFQR_OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		self::$cache = array_merge( self::defaults(), $stored );
		return self::$cache;
	}

	/**
	 * Drop the per-request cache. Called from update() and exposed for tests.
	 */
	public static function flush_cache() {
		self::$cache     = null;
		self::$ga4_cache = null;
	}

	/**
	 * Read a single setting key with default fallback.
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Persist the entire settings array (overwrites). Always routes through sanitize()
	 * so callers can't bypass validation.
	 */
	public static function update( array $values ) {
		update_option( CFQR_OPTION_KEY, self::sanitize( $values ) );
		self::flush_cache();
	}

	/**
	 * Resolve the GA4 Measurement ID via the documented detection chain:
	 *   1. Plugin setting
	 *   2. CFQR_GA4_ID PHP constant
	 *   3. Site Kit by Google option
	 * Returns empty string when none found.
	 */
	public static function resolve_ga4_id() {
		if ( null !== self::$ga4_cache ) {
			return self::$ga4_cache;
		}

		$explicit = trim( (string) self::get( 'ga4_measurement_id' ) );
		if ( '' !== $explicit ) {
			self::$ga4_cache = $explicit;
			return self::$ga4_cache;
		}

		if ( defined( 'CFQR_GA4_ID' ) && is_string( CFQR_GA4_ID ) && '' !== CFQR_GA4_ID ) {
			self::$ga4_cache = CFQR_GA4_ID;
			return self::$ga4_cache;
		}

		$sitekit = get_option( 'googlesitekit_analytics-4_settings' );
		if ( is_array( $sitekit ) && ! empty( $sitekit['measurementID'] ) ) {
			self::$ga4_cache = (string) $sitekit['measurementID'];
			return self::$ga4_cache;
		}

		self::$ga4_cache = '';
		return self::$ga4_cache;
	}

	/**
	 * Build a GA4 deep link to the configured property's reports area.
	 *
	 * When a property ID is configured we land the user in the right property's
	 * "Reports → Intelligent Home" view; when no property is configured we fall
	 * back to the GA4 home page. Either way the user has to apply the
	 * utm_content filter themselves — GA4's URL hash routing for pre-filtered
	 * reports is undocumented and changes regularly, so we don't try to deep
	 * link further.
	 */
	public static function build_ga4_link() {
		$prop = trim( (string) self::get( 'ga4_property_id' ) );
		if ( '' !== $prop && ctype_digit( $prop ) ) {
			return 'https://analytics.google.com/analytics/web/#/p' . $prop . '/reports/intelligenthome';
		}
		return 'https://analytics.google.com/analytics/web/';
	}

	/**
	 * Sanitize an incoming settings payload from the settings form.
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$out      = array();

		$ga4 = isset( $input['ga4_measurement_id'] ) ? trim( sanitize_text_field( wp_unslash( $input['ga4_measurement_id'] ) ) ) : '';
		// GA4 IDs follow the form G-XXXXXXX. Allow empty for unset.
		if ( '' === $ga4 || preg_match( '/^G-[A-Z0-9]{4,20}$/i', $ga4 ) ) {
			$out['ga4_measurement_id'] = $ga4;
		} else {
			$out['ga4_measurement_id'] = '';
			add_settings_error( CFQR_OPTION_KEY, 'cfqr_ga4_invalid', __( 'GA4 Measurement ID must be in the form G-XXXXXXX.', 'cf-qr-redirect' ) );
		}

		// GA4 numeric property ID — digits only, no length cap (Google has changed it
		// over time). Stored as a string for consistency with the option shape.
		$prop                   = isset( $input['ga4_property_id'] ) ? trim( (string) wp_unslash( $input['ga4_property_id'] ) ) : '';
		$prop                   = preg_replace( '/[^0-9]/', '', $prop );
		$out['ga4_property_id'] = $prop;

		$mode                          = isset( $input['default_analytics_mode'] ) ? sanitize_key( $input['default_analytics_mode'] ) : $defaults['default_analytics_mode'];
		$out['default_analytics_mode'] = in_array( $mode, array( 'utm', 'intermediate' ), true ) ? $mode : 'utm';

		$out['default_utm_source'] = isset( $input['default_utm_source'] )
			? sanitize_text_field( wp_unslash( $input['default_utm_source'] ) )
			: $defaults['default_utm_source'];
		if ( '' === $out['default_utm_source'] ) {
			$out['default_utm_source'] = $defaults['default_utm_source'];
		}

		$out['default_utm_medium'] = isset( $input['default_utm_medium'] )
			? sanitize_text_field( wp_unslash( $input['default_utm_medium'] ) )
			: $defaults['default_utm_medium'];
		if ( '' === $out['default_utm_medium'] ) {
			$out['default_utm_medium'] = $defaults['default_utm_medium'];
		}

		$status                 = isset( $input['redirect_status'] ) ? absint( $input['redirect_status'] ) : 302;
		$out['redirect_status'] = in_array( $status, array( 301, 302 ), true ) ? $status : 302;

		$length = isset( $input['short_code_length'] ) ? absint( $input['short_code_length'] ) : 8;
		if ( $length < 6 ) {
			$length = 6;
		} elseif ( $length > 12 ) {
			$length = 12;
		}
		$out['short_code_length'] = $length;

		return $out;
	}
}
