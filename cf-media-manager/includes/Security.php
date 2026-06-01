<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX security gate — single source of truth for the
 * nonce + capability check every browser-driven endpoint must pass.
 *
 * Why a static helper instead of duplicating the check on each class:
 * before this consolidation Ajax::authorize() and AltTextManager::authorize()
 * had identical implementations. If a future endpoint class forgot to add
 * its own, or if one drifted (e.g. a developer raises the capability bar
 * on one class but not the other), the security boundary silently weakens
 * with nothing in CI to catch it. Routing every endpoint through one
 * method means the only way to break the gate is to bypass this class
 * entirely — visible to any reviewer.
 *
 * The static method writes the HTTP response and exits on failure, so
 * callers can rely on execution continuing only when both checks pass.
 */
final class Security {

	/**
	 * Run the standard AJAX nonce + manage_options check. On failure,
	 * emits an HTTP 403 JSON error and calls wp_die — execution does
	 * not return to the caller.
	 */
	public static function authorize_ajax(): void {
		check_ajax_referer( Plugin::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'cf-media-manager' ), 403 );
		}
	}

	/**
	 * Network-scoped variant. Behaves identically to {@see authorize_ajax()}
	 * on single-site (requires `manage_options`); on multisite it raises the
	 * bar to `manage_network_options`.
	 *
	 * Several endpoints have network-wide blast radius — cross-site cache
	 * purges, the variant manifest scan (which walks the shared uploads tree
	 * on hosts that share it across sites), the queue, and settings writes
	 * for the network. `manage_options` is a per-site capability and is the
	 * wrong gate for those: a site-admin on subsite B should not be able to
	 * trigger an action whose effects ripple into subsite A.
	 *
	 * The nonce action is passed explicitly so a future endpoint that uses
	 * a non-default nonce (e.g. a settings form nonce) can route through
	 * the same gate without re-implementing it.
	 *
	 * @param string $nonce_action Nonce action name passed to check_ajax_referer.
	 */
	public static function authorize_ajax_network( string $nonce_action ): void {
		check_ajax_referer( $nonce_action, 'nonce' );
		$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error(
				is_multisite()
					? __( 'Unauthorized — requires network-admin capability.', 'cf-media-manager' )
					: __( 'Unauthorized', 'cf-media-manager' ),
				403
			);
		}
	}

	private function __construct() {}
}
